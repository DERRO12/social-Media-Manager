<?php

namespace App\Services\Sms;

/** Phone numbers in the international format SMS gateways expect, digits only (e.g. 256772123456). */
class Phone
{
    /** Default country code for local numbers like 0772 123456 (Uganda). */
    public const COUNTRY = '256';

    public static function normalise(?string $number, string $country = self::COUNTRY): ?string
    {
        $raw = trim((string) $number);
        if ($raw === '') {
            return null;
        }

        $international = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $digits = preg_replace('/\D/', '', $raw);
        if (str_starts_with($raw, '00')) {
            $digits = substr($digits, 2);
        }

        $digits = match (true) {
            $international => $digits,
            str_starts_with($digits, $country) => $digits,
            str_starts_with($digits, '0') && strlen($digits) === 10 => $country.substr($digits, 1), // 0772123456
            strlen($digits) === 9 => $country.$digits,                                             // 772123456
            default => $digits,
        };

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }

    /** "256772123456" → "+256 772 123 456" for display. */
    public static function display(?string $number): string
    {
        if (! $number) {
            return '';
        }

        return str_starts_with($number, self::COUNTRY) && strlen($number) === 12
            ? '+256 '.substr($number, 3, 3).' '.substr($number, 6, 3).' '.substr($number, 9)
            : '+'.$number;
    }

    /**
     * How many SMS a message takes: 160 characters (153 per part when longer), or 70 / 67 when it
     * has characters outside the basic SMS alphabet, such as emoji.
     *
     * @return array{characters: int, parts: int, unicode: bool}
     */
    public static function parts(string $message): array
    {
        $unicode = preg_match('/[^\x{0A}\x{0D}\x{20}-\x{7E}£¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ¡ÄÖÑÜ§¿äöñüà€]/u', $message) === 1;
        $length = mb_strlen($message);
        [$single, $multi] = $unicode ? [70, 67] : [160, 153];

        return [
            'characters' => $length,
            'parts' => $length <= $single ? 1 : (int) ceil($length / $multi),
            'unicode' => $unicode,
        ];
    }
}
