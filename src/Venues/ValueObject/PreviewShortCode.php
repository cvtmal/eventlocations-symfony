<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * The short code of a preview link (G11): ten characters a letter prints after `/v/` and its QR code encodes, so a
 * person can type the link from paper. Upper-case letters and digits without 0, 1, I, L and O, which read alike: 31
 * signs, about 50 bits, so guessing one of a few hundred live links is hopeless. Unlike the token it is stored
 * (`venue_previews.short_code`, unique, `chk_venue_previews_short_code`), so it is a secret too: never logged.
 */
final readonly class PreviewShortCode
{
    public const string ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const int LENGTH = 10;

    private function __construct()
    {
    }

    public static function generate(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    /**
     * The code as stored, from what someone typed (lower case and surrounding blanks allowed); null when it cannot be
     * one, so a wrong code costs no query.
     */
    public static function normalise(string $typed): ?string
    {
        $code = strtoupper(trim($typed));

        return 1 === preg_match('/^['.self::ALPHABET.']{'.self::LENGTH.'}$/D', $code) ? $code : null;
    }
}
