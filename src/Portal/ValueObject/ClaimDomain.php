<?php

declare(strict_types=1);

namespace App\Portal\ValueObject;

/**
 * G6: whether a claimant's address belongs to the venue's own website, which lets the code alone grant access. The
 * address's domain must be exactly the website's host without `www.` (`info@hotel-adler.ch` for
 * `https://www.hotel-adler.ch/`), and the website must be a site's home page (no path, or only a language segment
 * such as `/de/`): a venue that is one page on a university's, a city's or a chain's site
 * (`https://www.ethz.ch/saal`) would otherwise hand every address of that organisation the entry. Subdomain
 * addresses, siblings under a shared suffix (`evil.co.uk` for `adler.co.uk`) and a site on a subdomain with mail on
 * the parent never match, so no public suffix list is needed: they go to staff, the safe side. Mail providers and
 * hosting or social platforms never match, on either side.
 */
final readonly class ClaimDomain
{
    /** Domains anybody can get an address at, or a page on; their subdomains count too. */
    public const array GENERIC = [
        // Mail providers
        'gmail.com', 'googlemail.com', 'outlook.com', 'outlook.de', 'outlook.fr', 'hotmail.com', 'hotmail.ch',
        'hotmail.de', 'hotmail.fr', 'live.com', 'live.de', 'msn.com', 'yahoo.com', 'yahoo.de', 'yahoo.fr', 'ymail.com',
        'icloud.com', 'me.com', 'mac.com', 'aol.com', 'gmx.ch', 'gmx.net', 'gmx.de', 'gmx.at', 'gmx.com', 'web.de',
        't-online.de', 'freenet.de', 'bluewin.ch', 'bluemail.ch', 'sunrise.ch', 'hispeed.ch', 'swissonline.ch',
        'green.ch', 'quickline.ch', 'vtxmail.ch', 'netplus.ch', 'mail.ch', 'protonmail.com', 'protonmail.ch',
        'proton.me', 'pm.me', 'tutanota.com', 'tuta.io', 'zoho.com', 'yandex.com', 'mail.com', 'orange.fr', 'free.fr',
        'libero.it',
        // Hosting, social and directory platforms
        'google.com', 'business.site', 'facebook.com', 'instagram.com', 'linkedin.com', 'x.com', 'twitter.com',
        'youtube.com', 'tiktok.com', 'wixsite.com', 'wix.com', 'jimdo.com', 'jimdosite.com', 'squarespace.com',
        'weebly.com', 'wordpress.com', 'linktr.ee', 'tripadvisor.com', 'tripadvisor.ch', 'booking.com', 'local.ch',
        'search.ch', 'localsearch.ch',
    ];

    private function __construct()
    {
    }

    public static function matches(string $email, ?string $website): bool
    {
        $domain = self::emailDomain($email);
        $site = null === $website ? null : self::siteDomain($website);
        if (null === $domain || null === $site || self::isGeneric($domain) || self::isGeneric($site)) {
            return false;
        }

        return $domain === $site;
    }

    private static function emailDomain(string $email): ?string
    {
        $at = strrpos($email, '@');

        return false === $at ? null : self::normalise(substr($email, $at + 1));
    }

    private static function siteDomain(string $website): ?string
    {
        $parts = parse_url(trim($website));
        $host = \is_array($parts) ? ($parts['host'] ?? null) : null;
        if (!\is_string($host) || 1 !== preg_match('#^(/([a-z]{2}(-[a-z]{2})?/?)?)?$#Di', $parts['path'] ?? '')) {
            return null;
        }
        $host = self::normalise($host);
        if (null !== $host && str_starts_with($host, 'www.')) {
            $host = self::normalise(substr($host, 4));
        }

        return $host;
    }

    /**
     * Lower case, ASCII (an umlaut domain in either spelling), no trailing dot; null for an IP address, a single
     * label or anything that is no host name.
     */
    private static function normalise(string $host): ?string
    {
        $host = rtrim(mb_strtolower(trim($host)), '.');
        if ('' === $host || false !== filter_var(trim($host, '[]'), \FILTER_VALIDATE_IP)) {
            return null;
        }
        $ascii = idn_to_ascii($host, \IDNA_NONTRANSITIONAL_TO_ASCII, \INTL_IDNA_VARIANT_UTS46);
        if (false === $ascii || !str_contains($ascii, '.') || 1 !== preg_match('/^[a-z0-9.-]+$/D', $ascii) || str_contains($ascii, '..')) {
            return null;
        }

        return $ascii;
    }

    private static function isGeneric(string $domain): bool
    {
        foreach (self::GENERIC as $generic) {
            if ($domain === $generic || str_ends_with($domain, '.'.$generic)) {
                return true;
            }
        }

        return false;
    }
}
