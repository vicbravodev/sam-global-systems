<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Data\RecipientDescriptor;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Models\Notification;
use App\Models\Membership;

class ResolveRecipients
{
    /**
     * @return array<int, RecipientDescriptor>
     */
    public function execute(Notification $notification): array
    {
        return $this->explain($notification)['descriptors'];
    }

    /**
     * La misma resolución, con de dónde salieron los destinatarios y cuántos
     * candidatos se descartaron por motivo (sólo conteos: nunca direcciones).
     *
     * @return array{descriptors: list<RecipientDescriptor>, source: 'explicit'|'team_members', candidates_count: int, dropped_count_by_reason: array<string, int>}
     */
    public function explain(Notification $notification): array
    {
        $payload = $notification->payload_json ?? [];

        $explicit = $payload['recipients'] ?? null;

        if (is_array($explicit) && count($explicit) > 0) {
            return $this->buildExplicit($explicit);
        }

        return $this->buildFromTeamMembers($notification);
    }

    /**
     * @param  array<array-key, mixed>  $explicit  Viene del payload: cada entrada se valida aquí.
     * @return array{descriptors: list<RecipientDescriptor>, source: 'explicit', candidates_count: int, dropped_count_by_reason: array<string, int>}
     */
    private function buildExplicit(array $explicit): array
    {
        $descriptors = [];
        $dropped = [];

        foreach ($explicit as $entry) {
            if (! is_array($entry)) {
                $dropped['not_an_array'] = ($dropped['not_an_array'] ?? 0) + 1;

                continue;
            }

            $address = $entry['address'] ?? null;

            if (! is_string($address) || $address === '') {
                $dropped['no_address'] = ($dropped['no_address'] ?? 0) + 1;

                continue;
            }

            $type = isset($entry['recipient_type']) && is_string($entry['recipient_type'])
                ? (RecipientType::tryFrom($entry['recipient_type']) ?? RecipientType::ExternalContact)
                : RecipientType::ExternalContact;

            $email = isset($entry['email']) && is_string($entry['email'])
                ? $entry['email']
                : (str_contains($address, '@') ? $address : null);

            $phone = isset($entry['phone']) && is_string($entry['phone'])
                ? $entry['phone']
                : ($this->looksLikePhone($address) ? $address : null);

            $descriptors[] = new RecipientDescriptor(
                recipientType: $type,
                address: $address,
                email: $email,
                phone: $phone,
                name: isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : null,
                referenceId: isset($entry['recipient_reference_id']) ? (string) $entry['recipient_reference_id'] : null,
                channelPreference: isset($entry['channel_preference']) && is_string($entry['channel_preference']) ? $entry['channel_preference'] : null,
                role: isset($entry['role']) && is_string($entry['role']) ? $entry['role'] : null,
                metadata: isset($entry['metadata']) && is_array($entry['metadata']) ? $entry['metadata'] : null,
            );
        }

        return [
            'descriptors' => $descriptors,
            'source' => 'explicit',
            'candidates_count' => count($explicit),
            'dropped_count_by_reason' => $dropped,
        ];
    }

    /**
     * @return array{descriptors: list<RecipientDescriptor>, source: 'team_members', candidates_count: int, dropped_count_by_reason: array<string, int>}
     */
    private function buildFromTeamMembers(Notification $notification): array
    {
        $memberships = Membership::with('user')
            ->where('team_id', $notification->team_id)
            ->get();

        // Quienes ya reciben su propio aviso (p. ej. la persona en turno, que
        // va por canales pagados en otra notificación) no se repiten aquí.
        $excluded = array_map('intval', array_filter(
            (array) ($notification->payload_json['exclude_user_ids'] ?? []),
            'is_numeric',
        ));

        $descriptors = [];
        $dropped = [];

        foreach ($memberships as $membership) {
            $user = $membership->user;

            if ($user !== null && in_array($user->id, $excluded, true)) {
                $dropped['excluded'] = ($dropped['excluded'] ?? 0) + 1;

                continue;
            }

            if ($user === null || in_array($user->email, ['', '0'], true)) {
                $reason = $user !== null ? 'no_email' : 'no_user';
                $dropped[$reason] = ($dropped[$reason] ?? 0) + 1;

                continue;
            }

            $descriptors[] = new RecipientDescriptor(
                recipientType: RecipientType::User,
                address: $user->email,
                email: $user->email,
                // Sólo el teléfono verificado por OTP recibe SMS/WhatsApp/voz.
                phone: $user->verifiedPhone(),
                name: $user->name,
                referenceId: (string) $user->id,
                role: $membership->getRawOriginal('role'),
            );
        }

        return [
            'descriptors' => $descriptors,
            'source' => 'team_members',
            'candidates_count' => $memberships->count(),
            'dropped_count_by_reason' => $dropped,
        ];
    }

    private function looksLikePhone(string $value): bool
    {
        return preg_match('/^\+[0-9]{8,15}$/', trim($value)) === 1;
    }
}
