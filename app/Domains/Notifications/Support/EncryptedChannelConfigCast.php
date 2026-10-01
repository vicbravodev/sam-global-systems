<?php

namespace App\Domains\Notifications\Support;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Cast for `notification_channels.config_json` that transparently encrypts a
 * whitelist of sensitive keys (platform Slack/Push/Webhook secrets set from
 * the super-admin console) at rest while keeping the rest of the JSON
 * readable. Drivers consume plaintext values. Twilio credentials are not
 * here: they live only in platform env (TWILIO_*).
 *
 * Encrypted leaves are stored as `{"__enc": "<ciphertext>"}` so a reload
 * after a partial write still decrypts cleanly.
 *
 * El set acepta cualquier valor (un escalar se guarda tal cual en JSON).
 *
 * @implements CastsAttributes<array<string, mixed>|null, mixed>
 */
class EncryptedChannelConfigCast implements CastsAttributes
{
    /** @var list<string> */
    public const SENSITIVE_KEYS = [
        'secret',
        'webhook_secret',
        'api_key',
        'api_secret',
        'server_key',
        'firebase_credentials',
        'slack_webhook_url',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        if (! is_array($decoded)) {
            return null;
        }

        return $this->walk($decoded, decrypt: true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => json_encode(null)];
        }

        if (! is_array($value)) {
            return [$key => json_encode($value)];
        }

        return [$key => json_encode($this->walk($value, decrypt: false))];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function walk(array $config, bool $decrypt): array
    {
        foreach ($config as $k => $v) {
            $isSensitive = in_array($k, self::SENSITIVE_KEYS, true);

            if ($isSensitive) {
                if ($v === null || $v === '') {
                    continue;
                }

                $config[$k] = $decrypt
                    ? $this->decryptLeaf($v)
                    : ['__enc' => Crypt::encryptString((string) $v)];

                continue;
            }

            if (is_array($v)) {
                $config[$k] = $this->walk($v, $decrypt);
            }
        }

        return $config;
    }

    private function decryptLeaf(mixed $value): string
    {
        if (is_array($value)) {
            $cipher = $value['__enc'] ?? null;

            if (! is_string($cipher)) {
                return '';
            }

            try {
                return Crypt::decryptString($cipher);
            } catch (DecryptException) {
                return '';
            }
        }

        return is_string($value) ? $value : (string) $value;
    }
}
