<?php

namespace App\Domains\Access\Actions;

use App\Contracts\Notifications\ChannelDriverRegistry;
use App\Domains\Access\Data\OtpResult;
use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Models\User;
use App\Support\OtpCacheKeys;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;

class SendPhoneOtp
{
    public function __construct(
        private readonly ChannelDriverRegistry $drivers,
        private readonly RecordUsageEvent $recordUsage,
        private readonly RecordAuditEntry $audit,
    ) {}

    public function execute(User $user, int $teamId): OtpResult
    {
        $phone = trim((string) $user->phone);

        if (! PhoneNumber::isE164($phone)) {
            return OtpResult::failure('no_phone');
        }

        $channel = NotificationChannel::query()
            ->usableByTeam($teamId)
            ->where('channel_type', ChannelType::Sms)
            ->first();

        if ($channel === null) {
            $this->record($user, $teamId, 'phone_otp.send_failed', 'no_sms_channel');

            return OtpResult::failure('no_sms_channel');
        }

        if (! $this->withinDailyCaps((int) $user->id, $teamId)) {
            $this->record($user, $teamId, 'phone_otp.send_failed', 'daily_limit');

            return OtpResult::failure('daily_limit');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // El código queda atado al número al que se envía: si el usuario
        // cambia de teléfono, el código viejo ya no verifica el nuevo.
        Cache::put(
            OtpCacheKeys::forUser((int) $user->id),
            ['code' => $code, 'attempts' => 0, 'phone' => $phone],
            OtpCacheKeys::TTL_SECONDS,
        );

        $rendered = new RenderedNotification(
            channelType: ChannelType::Sms,
            address: $phone,
            subject: null,
            body: "Tu código de verificación SAM es {$code}. Vence en 5 minutos.",
        );

        $result = $this->drivers->driverFor(ChannelType::Sms)->send($rendered, $channel);

        $this->recordUsage->execute(
            teamId: $teamId,
            meterCode: 'otp_sms_sent',
            quantity: 1,
            eventKey: "otp_sms_{$user->id}_".now()->valueOf(),
        );

        $this->record($user, $teamId, $result->success ? 'phone_otp.sent' : 'phone_otp.send_failed', $result->success ? 'sent' : 'delivery_failed');

        return $result->success ? OtpResult::success() : OtpResult::failure('delivery_failed');
    }

    /**
     * Cuenta el envío contra los topes diarios (usuario y team) y dice si
     * cabe. Las claves incluyen el team_id (§2.1) y caducan al acabar el día.
     */
    private function withinDailyCaps(int $userId, int $teamId): bool
    {
        $userKey = OtpCacheKeys::dailyForUser($userId);
        $teamKey = OtpCacheKeys::dailyForTeam($teamId);

        if ((int) Cache::get($userKey, 0) >= OtpCacheKeys::DAILY_PER_USER
            || (int) Cache::get($teamKey, 0) >= OtpCacheKeys::DAILY_PER_TEAM) {
            return false;
        }

        $expiresAt = now()->endOfDay();
        Cache::add($userKey, 0, $expiresAt);
        Cache::add($teamKey, 0, $expiresAt);
        Cache::increment($userKey);
        Cache::increment($teamKey);

        return true;
    }

    private function record(User $user, int $teamId, string $action, string $outcome): void
    {
        // Audit the OUTCOME only — never the code or the full number (PII).
        $this->audit->execute(
            actorType: AuditActorType::User,
            actorId: (int) $user->id,
            action: $action,
            category: AuditCategory::Security,
            entityType: 'User',
            entityId: (int) $user->id,
            summary: "Phone OTP {$outcome} for user {$user->id}",
            teamId: $teamId,
            metadata: ['outcome' => $outcome],
        );
    }
}
