<?php

declare(strict_types=1);

namespace App\Venues\SiteImport;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Phone numbers, e-mail addresses and postal addresses of a page: from `tel:` and `mailto:` links and from
 * address markup (high confidence), then from the visible text (low). Swiss formats only for phone and postcode:
 * the site lists Swiss venues, and a looser pattern would find prices and years.
 */
final class ContactReader
{
    private const string STREET = '\p{Lu}[\p{L}.\'’ \-]{2,40}?\s\d{1,4}\s?[a-zA-Z]?(?:\s?[\-\/]\s?\d{1,4}[a-zA-Z]?)?';
    private const string ZIP_CITY = '(?:CH[\s\-])?([1-9]\d{3})\s+(\p{Lu}[\p{L}.\'’\/() \-]{1,40}?)';
    private const int MAX_ANCHORS = 1500;
    private const int MAX_ADDRESS_ELEMENTS = 20;
    private const int MAX_LINES = 4000;
    private const string LABEL = '/^(?:Adresse|Anschrift|Standort|Besucheradresse|Postadresse|Address)\s*:\s*/iu';
    private const string COUNTRY = '(?:Schweiz|Suisse|Svizzera|Switzerland|CH)';
    private const array NOISE_DOMAINS = ['example.com', 'example.org', 'domain.com', 'email.com', 'sentry.io', 'wixpress.com', 'sentry-next.wixpress.com', 'yoursite.com', 'yourdomain.com'];
    private const array ASSET_SUFFIXES = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'css', 'js', 'avif'];

    /**
     * @param list<string> $lines the visible text of the page, see PageText
     */
    public function read(Crawler $page, array $lines, string $sourceUrl, FactSet $facts): void
    {
        foreach (DomElements::of($page->filter('a[href]'), self::MAX_ANCHORS) as $link) {
            $href = trim($link->getAttribute('href'));
            if (0 === stripos($href, 'tel:')) {
                $this->phone(rawurldecode(substr($href, 4)), 0.8, 'link', $sourceUrl, $facts);
            } elseif (0 === stripos($href, 'mailto:')) {
                $this->email(rawurldecode(explode('?', substr($href, 7), 2)[0]), 0.8, 'link', $sourceUrl, $facts);
            }
        }

        foreach (DomElements::of($page->filter('address, [itemprop="address"], [itemtype*="PostalAddress"]'), self::MAX_ADDRESS_ELEMENTS) as $element) {
            $this->addressElement($element, $sourceUrl, $facts);
        }

        $lines = \array_slice($lines, 0, self::MAX_LINES);
        $this->addressFromLines($lines, 0.5, 'text', $sourceUrl, $facts);

        foreach ($lines as $line) {
            if (preg_match_all('/(?:^|[^\d+])((?:\+|00)41[\s().\/\-]*\(?0?\)?[\s().\/\-]*\d{2}[\s().\/\-]*\d{3}[\s.\-]*\d{2}[\s.\-]*\d{2}|0\d{2}[\s.\/\-]\d{3}[\s.\-]\d{2}[\s.\-]\d{2})(?!\d)/u', $line, $matches) > 0) {
                foreach ($matches[1] as $number) {
                    $this->phone($number, 0.5, 'text', $sourceUrl, $facts);
                }
            }
            if (str_contains($line, '@') && preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/', $line, $matches) > 0) {
                foreach ($matches[0] as $address) {
                    $this->email($address, 0.5, 'text', $sourceUrl, $facts);
                }
            }
        }
    }

    public function phone(string $raw, float $confidence, string $via, string $sourceUrl, FactSet $facts): void
    {
        $digits = (string) preg_replace('/\D+/', '', $raw);
        if (str_starts_with($digits, '0041')) {
            $digits = '41'.substr($digits, 4);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '41'.substr($digits, 1);
        }
        // "+41 (0)61 ..." keeps the national trunk zero in brackets; no Swiss number has a 0 after the country code.
        if (str_starts_with($digits, '410')) {
            $digits = '41'.substr($digits, 3);
        }
        if (\strlen($digits) < 9 || \strlen($digits) > 15) {
            return;
        }

        $facts->add('phone', 11 === \strlen($digits) && str_starts_with($digits, '41')
            ? \sprintf('+41 %s %s %s %s', substr($digits, 2, 2), substr($digits, 4, 3), substr($digits, 7, 2), substr($digits, 9, 2))
            : '+'.$digits, $confidence, $sourceUrl, $via);
    }

    public function email(string $raw, float $confidence, string $via, string $sourceUrl, FactSet $facts): void
    {
        $address = strtolower(trim($raw));
        if (false === filter_var($address, \FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $domain = substr($address, (int) strrpos($address, '@') + 1);
        $suffix = substr($domain, (int) strrpos($domain, '.') + 1);
        if (\in_array($domain, self::NOISE_DOMAINS, true) || \in_array($suffix, self::ASSET_SUFFIXES, true)) {
            return;
        }

        $facts->add('email', $address, $confidence, $sourceUrl, $via);
    }

    private function addressElement(\DOMElement $element, string $sourceUrl, FactSet $facts): void
    {
        $crawler = new Crawler($element);
        $part = static function (string $property) use ($crawler): string {
            $node = $crawler->filter('[itemprop="'.$property.'"]');

            return $node->count() > 0 ? trim((string) preg_replace('/\s+/u', ' ', $node->first()->text(''))) : '';
        };

        $street = $part('streetAddress');
        $postalCode = $part('postalCode');
        if ('' !== $street && '' !== $postalCode) {
            $this->address($street, $postalCode, $part('addressLocality'), $part('addressCountry') ?: null, 0.8, 'microdata', $sourceUrl, $facts);

            return;
        }

        $this->addressFromLines(PageText::lines($element), 0.7, 'html', $sourceUrl, $facts);
    }

    /**
     * A postal address is a street with a number next to a postcode and town: on one line, on two lines or between the
     * separators of a footer ("Name · Hallenweg 8 · 6300 Zug"). Every line is cut at its separators and the pieces are
     * read in order, so a street piece followed by a postcode piece is an address.
     *
     * @param list<string> $lines
     */
    private function addressFromLines(array $lines, float $confidence, string $via, string $sourceUrl, FactSet $facts): void
    {
        $street = '(?<street>'.self::STREET.')';
        $zipCity = self::ZIP_CITY;
        $previous = null;
        foreach ($lines as $line) {
            $pieces = preg_split('/\s*[·•|,;]\s*|\s+[–—-]\s+/u', $line) ?: [$line];
            if (1 === preg_match('/^'.$street.'\s+'.$zipCity.'$/u', trim($line), $m) && !self::isBox($m['street'])) {
                $this->address($m['street'], $m[2], $m[3], null, $confidence, $via, $sourceUrl, $facts);
            }

            foreach ($pieces as $piece) {
                $piece = trim((string) preg_replace(self::LABEL, '', trim($piece)));
                if (null !== $previous && 1 === preg_match('/^'.$zipCity.'$/u', $piece, $m)) {
                    $this->address($previous, $m[1], $m[2], null, $confidence, $via, $sourceUrl, $facts);
                    $previous = null;
                    continue;
                }
                if (null !== $previous && 1 === preg_match('/^'.self::COUNTRY.'$/iu', $piece)) {
                    continue;
                }
                $previous = 1 === preg_match('/^'.$street.'$/u', $piece, $m) && !self::isBox($m['street']) ? $m['street'] : null;
            }
        }
    }

    private function address(string $street, string $postalCode, string $locality, ?string $country, float $confidence, string $via, string $sourceUrl, FactSet $facts): void
    {
        $zip = (int) $postalCode;
        if ($zip < 1000 || $zip > 9658) {
            return;
        }

        $facts->add('address', [
            'street' => trim($street),
            'postal_code' => $postalCode,
            'locality' => trim($locality),
            'country' => $country,
        ], $confidence, $sourceUrl, $via);
    }

    private static function isBox(string $street): bool
    {
        return 1 === preg_match('/^(Postfach|Case postale|PO Box|Boîte)/iu', $street);
    }
}
