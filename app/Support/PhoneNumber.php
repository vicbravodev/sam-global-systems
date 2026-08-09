<?php

namespace App\Support;

class PhoneNumber
{
    /**
     * The canonical E.164 contract used across the app (profile phone,
     * driver mobile contacts, SMS/voice recipients): a leading '+' followed
     * by 8 to 15 digits.
     */
    public const E164_PATTERN = '/^\+[0-9]{8,15}$/';

    public static function isE164(string $value): bool
    {
        return preg_match(self::E164_PATTERN, trim($value)) === 1;
    }

    /**
     * Conservative normalization: strip common separators, then accept only
     * if the result is already E.164. We never infer a country code (no
     * libphonenumber), so a local number without '+' returns null.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $cleaned = preg_replace('/[\s\-().]/', '', trim($raw)) ?? '';

        return self::isE164($cleaned) ? $cleaned : null;
    }
}
