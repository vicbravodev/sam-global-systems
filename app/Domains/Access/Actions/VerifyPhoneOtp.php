<?php

namespace App\Domains\Access\Actions;

use App\Domains\Access\Data\OtpResult;
use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Models\User;
use App\Support\OtpCacheKeys;
use Illuminate\Support\Facades\Cache;

class VerifyPhoneOtp
{
    public function __construct(
        private readonly RecordAuditEntry $audit,
    ) {}

    public function execute(User $user, int $teamId, string $code): OtpResult
    {
        $key = OtpCacheKeys::forUser((int) $user->id);
        $entry = Cache::get($key);

        if (! is_array($entry) || ! isset($entry['code'])) {
            return $this->fail($user, $teamId, 'expired');
        }

        if (($entry['attempts'] ?? 0) >= OtpCacheKeys::MAX_ATTEMPTS) {
            Cache::forget($key);

            return $this->fail($user, $teamId, 'too_many_attempts');
        }

        if (! hash_equals((string) $entry['code'], trim($code))) {
            Cache::put($key, ['code' => $entry['code'], 'attempts' => ($entry['attempts'] ?? 0) + 1], OtpCacheKeys::TTL_SECONDS);

            return $this->fail($user, $teamId, 'invalid_code');
        }

        $user->forceFill(['phone_verified_at' => now()])->save();
        Cache::forget($key);

        $this->audit($user, $teamId, 'phone_otp.verified', 'verified');

        return OtpResult::success();
    }

    private function fail(User $user, int $teamId, string $reason): OtpResult
    {
        $this->audit($user, $teamId, 'phone_otp.verify_failed', $reason);

        return OtpResult::failure($reason);
    }

    private function audit(User $user, int $teamId, string $action, string $outcome): void
    {
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
