<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * The short code of a preview link (G11): ten characters a letter prints after `/v/` and its QR code encodes, so a
 * person can type the link from paper. Upper-case letters and digits without 0, 1, I, L and O, which read alike: 31
 * signs, about 50 bits, so guessing one of a few hundred live links is hopeless. Unlike the token it is stored
 * (`venue_previews.short_code`, unique, `chk_venue_previews_short_code`), so it is a secret too: never logged.
 *
 * Stored and generated in upper case; printed, encoded and served in lower case (D53, {@see self::display()}).
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

    /**
     * The printed and canonical form of a stored code (D53): lower case, which letters, QR codes, the CMS field and
     * the print list show and `/v/` redirects to. Storage and {@see self::ALPHABET} stay upper case. There is no
     * look-alike mapping: 0/O/o and 1/I/i/l/L are all outside the alphabet in both cases, so no typed excluded sign
     * stands for exactly one allowed sign, and mapping signs inside the alphabet would turn a right code into a wrong one.
     */
    public static function display(string $stored): string
    {
        return strtolower($stored);
    }
}
