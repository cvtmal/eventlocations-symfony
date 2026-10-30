<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\Exception\InvalidWixExport;
use App\Venues\ValueObject\Content\LegacyVenue;

/**
 * Maps one CSV row to the paths #21 owns (D7–D11). Nothing is guessed: a value that fits no
 * rule is dropped and noted, and a row without what a venue needs is unresolved. Notes and
 * reasons never quote a cell.
 *
 * Not read, because they are #32's: contact person, Instagram, Typ, Preisniveau, Ambiente as
 * `ambience`, and the photos.
 */
final class LegacyVenueMapper
{
    public const string NOTE_PHONE_DROPPED = 'phone dropped: not a Swiss number';
    public const string NOTE_EMAIL_DROPPED = 'e-mail dropped: not an address';
    public const string NOTE_WEBSITE_DROPPED = 'website dropped: not an http(s) address';

    /** The labels of translations/geo+intl-icu.de.yaml, plus the two spellings of the CSV. */
    private const array CANTONS = [
        'Zürich' => 'zh',
        'Bern' => 'be',
        'Luzern' => 'lu',
        'Uri' => 'ur',
        'Schwyz' => 'sz',
        'Obwalden' => 'ow',
        'Nidwalden' => 'nw',
        'Glarus' => 'gl',
        'Zug' => 'zg',
        'Freiburg' => 'fr',
        'Solothurn' => 'so',
        'Basel-Stadt' => 'bs',
        'Basel-Landschaft' => 'bl',
        'Schaffhausen' => 'sh',
        'Appenzell Ausserrhoden' => 'ar',
        'Appenzell Innerrhoden' => 'ai',
        'St. Gallen' => 'sg',
        'Graubünden' => 'gr',
        'Aargau' => 'ag',
        'Thurgau' => 'tg',
        'Tessin' => 'ti',
        'Waadt' => 'vd',
        'Wallis' => 'vs',
        'Neuenburg' => 'ne',
        'Genf' => 'ge',
        'Jura' => 'ju',
        'Fribourg' => 'fr',
        'Rümlang' => 'zh',
    ];

    private const string SKIPPED_REGION = 'Liechtenstein';

    /** docs/content-format.schema.json and the matching CHECKs. */
    private const int NAME_MAX_LENGTH = 150;
    private const int REGION_MAX_LENGTH = 60;
    private const int CAPACITY_MAX = 50000;
    private const int EMAIL_MAX_LENGTH = 254;
    private const string EMAIL_PATTERN = '/^[^@\s]+@[^@\s]+\.[^@\s]+$/D';
    private const int WEBSITE_MAX_LENGTH = 500;
    private const string WEBSITE_PATTERN = '#^https?://#';

    /**
     * @param array<string, string> $row
     *
     * @throws InvalidWixExport for a row that cannot become a venue
     */
    public function map(array $row): LegacyVenue
    {
        $region = self::text($row['Region'] ?? '');
        if (self::SKIPPED_REGION === $region) {
            return LegacyVenue::skipped();
        }

        $id = $row['ID'] ?? '';
        if (1 !== preg_match('/^[0-9a-f]{8}/', $id)) {
            throw InvalidWixExport::unresolvedRow('the ID does not start with 8 hex digits');
        }
        $name = self::name($row['Name'] ?? '');
        $capacity = self::capacity($row['Kapazität'] ?? '');
        if (mb_strlen($region) > self::REGION_MAX_LENGTH) {
            throw InvalidWixExport::unresolvedRow(\sprintf('the Region is longer than %d characters', self::REGION_MAX_LENGTH));
        }

        try {
            $paragraphs = WixRichText::paragraphs($row['Vorstellungstext'] ?? '');
        } catch (\JsonException) {
            throw InvalidWixExport::unresolvedRow('the Vorstellungstext is not valid rich text');
        }
        ['text' => $description, 'rule' => $rule] = LegacyVenueText::description($paragraphs);
        ['text' => $summary, 'source' => $source] = LegacyVenueText::summary($description, self::text($row['Ambiente'] ?? ''));
        if ('' === $summary) {
            throw InvalidWixExport::unresolvedRow('neither a Vorstellungstext nor an Ambiente');
        }

        $notes = [];
        $phone = self::phone($row['Telefon'] ?? '', $notes);
        $email = self::contact($row['Email'] ?? '', self::EMAIL_PATTERN, self::EMAIL_MAX_LENGTH, self::NOTE_EMAIL_DROPPED, $notes);
        $website = self::contact($row['Website'] ?? '', self::WEBSITE_PATTERN, self::WEBSITE_MAX_LENGTH, self::NOTE_WEBSITE_DROPPED, $notes);

        if ('' === $region) {
            $notes[] = 'no Region';
        }
        if (3 === $rule) {
            $notes[] = 'description from long paragraphs: check it reads as prose';
        }
        if (4 === $rule) {
            $notes[] = 'no description';
        }
        if ('word' === $source) {
            $notes[] = 'summary cut at a word';
        }
        if ('ambience' === $source) {
            $notes[] = 'summary from Ambiente';
        }
        if (1 === preg_match('/\S+@\S+\.\S+|(\+41|\b0\d{2})[\s\d]{7,}/u', $summary."\n".$description)) {
            $notes[] = 'the text contains something like an address or a phone number';
        }

        return new LegacyVenue(
            substr($id, 0, 8),
            $name,
            $capacity,
            self::CANTONS[$region] ?? null,
            '' === $region || isset(self::CANTONS[$region]) ? null : $region,
            $phone,
            $email,
            $website,
            $summary,
            $description,
            $rule,
            $source,
            $notes,
        );
    }

    private static function name(string $value): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', self::text($value)));
        if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw InvalidWixExport::unresolvedRow(\sprintf('the Name is empty or longer than %d characters', self::NAME_MAX_LENGTH));
        }
        if ('' === SlugAllocator::base($name)) {
            throw InvalidWixExport::unresolvedRow('the Name gives no URL slug');
        }

        return $name;
    }

    private static function capacity(string $value): int
    {
        if (1 !== preg_match('/^(\d+) Person(en)?$/D', trim($value), $match)) {
            throw InvalidWixExport::unresolvedRow('the Kapazität is not "N Personen"');
        }
        $capacity = (int) $match[1];
        if ($capacity < 1 || $capacity > self::CAPACITY_MAX) {
            throw InvalidWixExport::unresolvedRow(\sprintf('the Kapazität is outside 1 to %d', self::CAPACITY_MAX));
        }

        return $capacity;
    }

    /**
     * @param list<string> $notes
     */
    private static function phone(string $value, array &$notes): ?string
    {
        $phone = ltrim((string) preg_replace('/\s+/u', '', $value), "'");
        if ('' === $phone) {
            return null;
        }
        if (1 === preg_match('/^0\d{9}$/D', $phone)) {
            return '+41'.substr($phone, 1);
        }
        if (1 === preg_match('/^\+41\d{9}$/D', $phone)) {
            return $phone;
        }
        $notes[] = self::NOTE_PHONE_DROPPED;

        return null;
    }

    /**
     * @param list<string> $notes
     */
    private static function contact(string $value, string $pattern, int $maxLength, string $note, array &$notes): ?string
    {
        $contact = trim($value);
        if ('' === $contact) {
            return null;
        }
        if (mb_strlen($contact) > $maxLength || 1 !== preg_match($pattern, $contact)) {
            $notes[] = $note;

            return null;
        }

        return $contact;
    }

    private static function text(string $value): string
    {
        $normalized = \Normalizer::normalize($value, \Normalizer::NFC);

        return trim(false === $normalized ? $value : $normalized);
    }
}
