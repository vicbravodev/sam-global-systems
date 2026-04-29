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
        $payload = $notification->payload_json ?? [];

        $explicit = $payload['recipients'] ?? null;

        if (is_array($explicit) && count($explicit) > 0) {
            return $this->buildExplicit($explicit);
        }

        return $this->buildFromTeamMembers($notification);
    }

    /**
     * @param  array<int, array<string, mixed>>  $explicit
     * @return array<int, RecipientDescriptor>
     */
    private function buildExplicit(array $explicit): array
    {
        $descriptors = [];

        foreach ($explicit as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $address = $entry['address'] ?? null;

            if (! is_string($address) || $address === '') {
                continue;
            }

            $type = isset($entry['recipient_type']) && is_string($entry['recipient_type'])
                ? (RecipientType::tryFrom($entry['recipient_type']) ?? RecipientType::ExternalContact)
                : RecipientType::ExternalContact;

            $descriptors[] = new RecipientDescriptor(
                recipientType: $type,
                address: $address,
                name: isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : null,
                referenceId: isset($entry['recipient_reference_id']) ? (string) $entry['recipient_reference_id'] : null,
                channelPreference: isset($entry['channel_preference']) && is_string($entry['channel_preference']) ? $entry['channel_preference'] : null,
                role: isset($entry['role']) && is_string($entry['role']) ? $entry['role'] : null,
                metadata: isset($entry['metadata']) && is_array($entry['metadata']) ? $entry['metadata'] : null,
            );
        }

        return $descriptors;
    }

    /**
     * @return array<int, RecipientDescriptor>
     */
    private function buildFromTeamMembers(Notification $notification): array
    {
        $memberships = Membership::with('user')
            ->where('team_id', $notification->team_id)
            ->get();

        $descriptors = [];

        foreach ($memberships as $membership) {
            $user = $membership->user;

            if (! $user || ! $user->email) {
                continue;
            }

            $descriptors[] = new RecipientDescriptor(
                recipientType: RecipientType::User,
                address: $user->email,
                name: $user->name,
                referenceId: (string) $user->id,
                role: $membership->getRawOriginal('role'),
            );
        }

        return $descriptors;
    }
}
