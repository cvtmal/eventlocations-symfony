<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\ValueObject\Content\OwnerFacts;
use App\Venues\ValueObject\OwnCatering;
use App\Venues\ValueObject\PartyAllowed;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\TermGroup;

/**
 * Maps one CSV row and its Vorstellungstext to the S14 facts #32 owns (research D3–D7,
 * D15–D17). Nothing is inferred (S14): a value counts only when a whole token or a whole
 * text is one of the listed forms, and everything else goes to a `*_note` or the report. That
 * is lower coverage than a keyword search on purpose, because a false "Zivile Trauung
 * möglich" is the failure that matters. Pure; notes never quote a cell.
 *
 * Tables hold folded forms (LegacyVenueText::fold()): NFC, whitespace collapsed, lower case,
 * trailing ` :.` removed. Adding a row is a change of meaning: say why in a comment.
 */
final class OwnerFactsMapper
{
    public const int NOTE_MAX_LENGTH = 250;
    public const int AMBIENCE_MAX_LENGTH = 60;

    public const string NOTE_PRICE_LEVEL_UNMAPPED = 'Preisniveau not mapped';
    public const string NOTE_TYPE_UNMAPPED = 'Typ not mapped';
    public const string NOTE_AMBIENCE_TOO_LONG = 'Ambiente longer than 60 characters: no ambience';
    public const string NOTE_OPENING_HOURS_CUT = 'opening_hours cut at 250 characters';
    public const string NOTE_CAPACITY_NOTE_CUT = 'capacity_note cut at 250 characters';
    public const string NOTE_PARTY_NOTE_CUT = 'party_note cut at 250 characters';
    public const string NOTE_PARKING_TEXT_CUT = 'parking_text cut at 250 characters';
    public const string NOTE_SEATED_OUT_OF_RANGE = 'seated capacity outside 1 to 50000: no number';
    public const string NOTE_STANDING_OUT_OF_RANGE = 'standing capacity outside 1 to 50000: no number';
    public const string NOTE_CAPACITY_SECTION = 'Kapazität section with several rooms: not parsed';
    public const string NOTE_CURFEW_CONFLICT = 'Sperrstunde and Party möglich? disagree: no curfew set';
    public const string NOTE_SERVICE_PROMPT = 'Service repeats the form prompt: no vendor from it';
    public const string NOTE_USAGE_PROSE = 'Nutzung left as prose';

    private const int CAPACITY_MAX = 50000;

    private const array PRICE_LEVELS = [
        'moderat' => PriceLevel::Moderate,
        'mittel' => PriceLevel::Medium,
        'exklusiv' => PriceLevel::Exclusive,
    ];

    /** All 12 values of the form's `Typ`; the first code is the main building (S14). */
    private const array TYPES = [
        'originelle location' => ['experience_creative'],
        'hotel' => ['hotel'],
        'restaurant' => ['restaurant'],
        'gasthof' => ['restaurant'],
        'burg & schloss' => ['castle'],
        'saal' => ['event_hall'],
        'eventhalle' => ['event_hall'],
        'gutshof & weingut' => ['brewery_winery'],
        'scheune' => ['farm_barn'],
        'hotel restaurant' => ['hotel', 'restaurant'],
        'kultur-gästehaus' => ['cultural_centre'],
        'waldhaus' => ['forest_hut'],
    ];

    /** Whole words of `Ambiente` that name the lake setting (PD11). */
    private const array LAKE_WORDS = ['see', 'seeanstoss', 'seeanstross', 'seeblick', 'seesicht', 'seenahe', 'seenähe'];

    /** A named lake (`…see`) is a word of at least this many letters; `Seele` is not one. */
    private const int LAKE_NAME_MIN_LENGTH = 6;

    private const array SEATED_LABELS = ['kapazität für gesetztes essen'];
    private const array STANDING_LABELS = ['kapazität für stehempfang / apéro', 'kapazität für stehempfang/apéro', 'kapazität für stehempfang'];
    private const array LANGUAGE_LABELS = ['sprachen die vom personal gesprochen werden', 'sprachen, die vom personal gesprochen werden'];
    private const string CURFEW_LABEL = 'sperrstunde';
    private const string PARTY_LABEL = 'party möglich?';
    private const string USAGE_LABEL = 'nutzung';
    private const string SERVICE_LABEL = 'service';

    /** Several rooms in one section: the numbers stay in the description (out of scope). */
    private const array UNPARSED_CAPACITY_LABELS = ['kapazität', 'kapazitäten'];

    private const array AMENITY_TOKENS = [
        'tanzfläche' => 'amenity.dance_floor',
        'terrasse' => 'surroundings.terrace',
        'rollstuhlgängig' => 'amenity.wheelchair_access',
        'behindertengerechte infrastruktur' => 'amenity.wheelchair_access',
        'behindertengerechte infrastruktur usw' => 'amenity.wheelchair_access',
        'barrierefreie infrastruktur' => 'amenity.wheelchair_access',
        'barrierefreier zugang' => 'amenity.wheelchair_access',
        'barrierefrei' => 'amenity.wheelchair_access',
        'eigene küche' => 'amenity.own_kitchen',
        'garten' => 'surroundings.garden',
        'essen im freien' => 'amenity.outdoor_dining',
        'empfang bzw. bankett im freien möglich' => 'amenity.outdoor_dining',
        'bankett im freien möglich' => 'amenity.outdoor_dining',
        'panoramablick' => 'amenity.panorama',
        'parkplätze' => 'amenity.parking',
        'genügend parkplätze' => 'amenity.parking',
        'parkplätze genügend' => 'amenity.parking',
        'parkplätze genügend vorhanden' => 'amenity.parking',
        'parkplätze vorhanden' => 'amenity.parking',
        'parkplätze (genügend)' => 'amenity.parking',
        'parkplätze (anzahl, genügend)' => 'amenity.parking',
        'grosser parkplatz' => 'amenity.parking',
        'kostenlose parkplätze' => 'amenity.parking',
        'gratis parkplätze' => 'amenity.parking',
        'viele parkplätze' => 'amenity.parking',
        'parkbereich' => 'amenity.parking_area',
        'übernachtung' => 'amenity.overnight',
        'übernachtungsmöglichkeiten' => 'amenity.overnight',
        'beamer' => 'technology.projector_screen',
        'leinwand' => 'technology.projector_screen',
        'beamer und leinwand' => 'technology.projector_screen',
        'seeblick' => 'amenity.lake_view',
        'seesicht' => 'amenity.lake_view',
        'musikanlage' => 'technology.sound_system',
        'musik-technik' => 'technology.sound_system',
        'kinderraum' => 'amenity.kids_room',
        'öv anbindung' => 'amenity.public_transport',
        'zapfengeld' => 'amenity.corkage',
        'catering möglich' => 'amenity.catering_available',
        'zelt' => 'amenity.tent',
        // Also sets faq.own_catering: allowed (S14, D17).
        'externe caterer erlaubt' => 'amenity.external_caterers',
        'bühne' => 'infrastructure.stage',
        'spielplatz' => 'surroundings.playground',
    ];

    /**
     * A parking token that states a count (D16). It sets `parking` and its own text becomes
     * `parking_text`. Each alternative is one form of the table; nothing wider.
     */
    private const string PARKING_COUNT = '/^(?:\d+ ?parkplätze(?: \(gratis\))?|ca\. \d+ parkplätze|parkplätze \d+|parkplätze \((?:anzahl:? |ca\. |über )?\d+\))$/uD';

    /** The newer form's `Zeremonie` checkboxes. */
    private const array CEREMONY_TOKENS = [
        'zivile trauung möglich' => 'ceremony.civil_ceremony',
        'freie trauung möglich' => 'ceremony.free_ceremony',
        'räumlichkeiten für freie trauung' => 'ceremony.free_ceremony',
        'kirche in gehdistanz' => 'ceremony.church_nearby',
        'schlechtwetter-alternative vorhanden' => 'ceremony.rain_alternative',
    ];

    /** The older form's `Einrichtung` options that state a ceremony (D7). */
    private const array OLDER_CEREMONY_TOKENS = [
        // D7: the owner's spot-check. Remove this line to stop setting civil_ceremony from the older form.
        self::SPOT_CHECK_TOKEN => 'ceremony.civil_ceremony',
        'freie trauung möglich' => 'ceremony.free_ceremony',
        'räumlichkeiten für freie trauung' => 'ceremony.free_ceremony',
        'offizielles standesamt-lokal' => 'ceremony.registry_office',
        'kapelle' => 'ceremony.chapel',
    ];

    private const string SPOT_CHECK_TOKEN = 'räumlichkeiten für standesamtliche hochzeiten';

    private const array DINING_TOKENS = [
        'vegetarisch' => 'dining.vegetarian',
        'vegetarische' => 'dining.vegetarian',
        'vegan' => 'dining.vegan',
        'glutenfrei' => 'dining.gluten_free',
        'allergiker' => 'dining.allergy_friendly',
        'kindermenu' => 'dining.kids_menu',
        'kindermenü' => 'dining.kids_menu',
        'diabetiker' => 'dining.diabetic',
        'hochzeitstorte' => 'dining.wedding_cake',
        'torte' => 'dining.wedding_cake',
        'bio' => 'dining.organic',
        'buffet' => 'dining.buffet',
        'grill' => 'dining.grill',
        'alles möglich' => 'dining.anything_possible',
        'halal' => 'dining.halal',
        'koscher' => 'dining.kosher',
    ];

    private const array VENDOR_TOKENS = [
        'dekoservice' => 'vendor.flowers_decor',
        'dekoration' => 'vendor.flowers_decor',
        'floristen' => 'vendor.flowers_decor',
        'floristin' => 'vendor.flowers_decor',
        'florist' => 'vendor.flowers_decor',
        'blumen/deko' => 'vendor.flowers_decor',
        'fotograf' => 'vendor.photography',
        'fotografen' => 'vendor.photography',
        'fotografie' => 'vendor.photography',
        'musiker' => 'vendor.musicians',
        'musikern' => 'vendor.musicians',
        'mobiliar' => 'vendor.furniture',
        'mobiliarvermietung' => 'vendor.furniture',
        // One token: the commas sit inside the parentheses, so no furniture.chairs (D3).
        'mobiliarvermietung (hussen, tische, stühle, partyzelt)' => 'vendor.furniture',
        'videograf' => 'vendor.videography',
        'trauredner' => 'vendor.officiant',
        'dj' => 'vendor.dj',
        'djs' => 'vendor.dj',
        'weddingplanner' => 'vendor.wedding_planner',
    ];

    /**
     * The older form's `Service` prompt, echoed by venues that answered with it (PD5). All eight
     * options in prompt order are not a selection, so a paragraph holding them sets nothing.
     */
    private const string SERVICE_PROMPT = '/weddingplanner, dekoservice, mobiliarvermietung \(hussen, tische, stühle(?:, partyzelt)?\), musiker, fotograf, videograf, trauredner/u';

    /** The token tables of each folded section label. */
    private const array TOKEN_SECTIONS = [
        'einrichtung' => [self::AMENITY_TOKENS, self::OLDER_CEREMONY_TOKENS],
        'infrastruktur' => [self::AMENITY_TOKENS],
        'zeremonie' => [self::CEREMONY_TOKENS],
        'essen' => [self::DINING_TOKENS],
        'essen / trinken' => [self::DINING_TOKENS],
        'service' => [self::VENDOR_TOKENS],
        'zusammenarbeit dienstleister?' => [self::VENDOR_TOKENS],
    ];

    private const array AMENITY_SECTIONS = ['einrichtung', 'infrastruktur'];

    /** `nicht exklusiv …` is multiple_weddings: that is the code's German label word for word (PD13). */
    private const array USAGE_TOKENS = [
        'exklusiv' => 'exclusive',
        'immer exklusiv' => 'exclusive',
        'exklusiv (nur eine hochzeit)' => 'exclusive',
        'exklusiv: nur eine hochzeit' => 'exclusive',
        'immer exklusiv mit nur einer hochzeit am selben tag' => 'exclusive',
        'exklusiv (nur eine hochzeit oder ein event gleichzeitig möglich)' => 'exclusive',
        'exklusiv (nur eine hochzeit, eine feier, ein seminar)' => 'exclusive',
        'nicht exklusiv' => 'multiple_weddings',
        'nicht exklusive' => 'multiple_weddings',
        'nicht exklusiv (mehrere veranstaltung gleichzeitig möglich)' => 'multiple_weddings',
        'nicht exklusiv (mehrere veranstaltungen gleichzeitig möglich)' => 'multiple_weddings',
        'mehrere hochzeiten' => 'multiple_weddings',
        'mehrere veranstaltung gleichzeitig möglich' => 'multiple_weddings',
        'mehrere veranstaltungen gleichzeitig möglich' => 'multiple_weddings',
        'hochzeit + normalbetrieb' => 'wedding_and_regular',
    ];

    private const array LANGUAGE_TOKENS = [
        'deutsch' => 'de',
        'de' => 'de',
        'd' => 'de',
        'englisch' => 'en',
        'english' => 'en',
        'en' => 'en',
        'e' => 'en',
        'französisch' => 'fr',
        'französich' => 'fr',
        'fr' => 'fr',
        'f' => 'fr',
        'italienisch' => 'it',
        'i' => 'it',
        'spanisch' => 'es',
        'portugiesisch' => 'pt',
        'schweizerdeutsch' => 'gsw',
        'ch' => 'gsw',
        'ch-deutsch' => 'gsw',
        'niederländisch' => 'nl',
        'tschechisch' => 'cs',
        'russisch' => 'ru',
        'ungarisch' => 'hu',
        'rumänisch' => 'ro',
        'rümanisch' => 'ro',
    ];

    private const array LIST_SEPARATORS = [',', ';', "\n"];
    private const array USAGE_SEPARATORS = [',', ';', "\n", ' oder ', ' und '];
    private const string LANGUAGE_SEPARATORS = '/[,;\n\/|&]| und |\. /iu';

    /** An optional lead word, a number or a range, an optional unit (D6), on folded text. */
    private const string CAPACITY = '/^(?:(?:max\.?|maximal|bis zu|bis|ca\.|circa|für|rund|etwa) ?)?(\d{1,3}(?:[\'’]\d{3})+|\d+)(?: ?(?:-|–|—|bis) ?(\d{1,3}(?:[\'’]\d{3})+|\d+))?(?: ?(?:personen|person|pers\.?|pax|gäste))?$/uD';
    private const string PLAIN_CAPACITY = '/^\d+ personen$/uD';

    private const array NO_CURFEW = ['nein', 'keine', 'keine sperrstunde', 'ohne sperrstunde', 'nein, keine bei hochzeiten'];
    private const string CURFEW_TIME = '/^(?:ja,? )?(?:(?:ab|bis|um) )?(\d{1,2})(?:[.:](\d{2}))? ?(?:uhr|h)?(?: (?:morgens|nachts))?(?: sperrstunde)?$/uD';
    private const string PARTY_PREFIX = '/^(ja|mit einschränkung|nein)(?!\p{L})(.*)$/usD';
    private const string PARTY_REST_TIME = '/^(?:(?:bis|ab|um) )?(\d{1,2})(?:[.:](\d{2}))? ?(?:uhr|h)?$/uD';
    private const array PARTY_ALLOWED = ['ja' => PartyAllowed::Yes, 'mit einschränkung' => PartyAllowed::Limited, 'nein' => PartyAllowed::No];
    private const array PARTY_NO_CURFEW = ['ohne sperrstunde', 'keine sperrstunde'];

    /**
     * @param array<string, string> $row        the CSV row
     * @param list<string>          $paragraphs WixRichText::paragraphs() of its Vorstellungstext
     */
    public function map(array $row, array $paragraphs): OwnerFacts
    {
        $notes = [];
        $unmapped = [];
        /** @var array<string, array<string, true>> $codes group to code set */
        $codes = ['suitable_for' => ['wedding' => true]];
        $main = [];

        $priceText = self::text($row['Preisniveau'] ?? '');
        $priceLevel = self::PRICE_LEVELS[LegacyVenueText::fold($priceText)] ?? null;
        if (null === $priceLevel) {
            $notes[] = self::NOTE_PRICE_LEVEL_UNMAPPED;
            $unmapped['preisniveau'] = '' === $priceText ? [] : [$priceText];
        }

        $typeText = self::text($row['Typ'] ?? '');
        $buildings = self::TYPES[LegacyVenueText::fold($typeText)] ?? [];
        if ([] === $buildings) {
            $notes[] = self::NOTE_TYPE_UNMAPPED;
            $unmapped['typ'] = '' === $typeText ? [] : [$typeText];
        } else {
            foreach ($buildings as $building) {
                $codes['building'][$building] = true;
            }
            $main['building'] = $buildings[0];
        }

        $ambience = self::text($row['Ambiente'] ?? '');
        $settings = self::setting($ambience);
        foreach ($settings as $setting) {
            $codes['setting'][$setting] = true;
        }
        if (1 === \count($settings)) {
            $main['setting'] = $settings[0];
        }
        if (mb_strlen($ambience) > self::AMBIENCE_MAX_LENGTH) {
            $notes[] = self::NOTE_AMBIENCE_TOO_LONG;
            $ambience = '';
        }

        $openingHours = self::fit(self::text($row['Öffnungszeiten'] ?? ''), self::NOTE_OPENING_HOURS_CUT, $notes);

        $sections = LegacyVenueText::sections($paragraphs);
        $parking = [];
        $spotCheck = false;
        foreach (self::TOKEN_SECTIONS as $label => $tables) {
            foreach ($sections[$label] ?? [] as $paragraph) {
                if (self::SERVICE_LABEL === $label && 1 === preg_match(self::SERVICE_PROMPT, LegacyVenueText::fold($paragraph))) {
                    $notes[] = self::NOTE_SERVICE_PROMPT;
                    continue;
                }
                foreach (self::tokens($paragraph, self::LIST_SEPARATORS) as [$original, $token]) {
                    $code = self::lookup($token, $tables);
                    if (null === $code && \in_array($label, self::AMENITY_SECTIONS, true) && 1 === preg_match(self::PARKING_COUNT, $token)) {
                        $code = 'amenity.parking';
                        $parking[] = $original;
                    }
                    if (null === $code) {
                        $unmapped[$label][] = $original;
                        continue;
                    }
                    [$group, $value] = explode('.', $code, 2);
                    $codes[$group][$value] = true;
                    if (self::SPOT_CHECK_TOKEN === $token) {
                        $spotCheck = true;
                    }
                }
            }
        }

        $usageProse = false;
        foreach ($sections[self::USAGE_LABEL] ?? [] as $paragraph) {
            foreach (self::tokens($paragraph, self::USAGE_SEPARATORS) as [$original, $token]) {
                if (isset(self::USAGE_TOKENS[$token])) {
                    $codes['usage'][self::USAGE_TOKENS[$token]] = true;
                } else {
                    $unmapped[self::USAGE_LABEL][] = $original;
                    $usageProse = true;
                }
            }
        }
        if ($usageProse) {
            $notes[] = self::NOTE_USAGE_PROSE;
        }

        foreach (self::LANGUAGE_LABELS as $label) {
            foreach ($sections[$label] ?? [] as $paragraph) {
                foreach (self::languages($paragraph) as [$original, $token]) {
                    if (isset(self::LANGUAGE_TOKENS[$token])) {
                        $codes['language'][self::LANGUAGE_TOKENS[$token]] = true;
                    } else {
                        $unmapped[$label][] = $original;
                    }
                }
            }
        }

        [$seated, $seatedNote] = self::capacity(self::section($sections, self::SEATED_LABELS), self::NOTE_SEATED_OUT_OF_RANGE, $notes);
        [$standing, $standingNote] = self::capacity(self::section($sections, self::STANDING_LABELS), self::NOTE_STANDING_OUT_OF_RANGE, $notes);
        $capacityNote = implode(' · ', array_filter([
            null === $seatedNote ? null : 'Gesetztes Essen: '.$seatedNote,
            null === $standingNote ? null : 'Apéro: '.$standingNote,
        ]));
        foreach (self::UNPARSED_CAPACITY_LABELS as $label) {
            if ([] !== ($sections[$label] ?? [])) {
                $notes[] = self::NOTE_CAPACITY_SECTION;
            }
        }

        [$hasCurfew, $curfewAt, $curfewNote] = self::curfew($sections[self::CURFEW_LABEL] ?? []);
        [$partyAllowed, $partyHasCurfew, $partyCurfewAt, $partyNote] = self::party($sections[self::PARTY_LABEL] ?? []);
        $conflict = null !== $hasCurfew && null !== $partyHasCurfew
            && ($hasCurfew !== $partyHasCurfew || (null !== $curfewAt && null !== $partyCurfewAt && $curfewAt !== $partyCurfewAt));
        if ($conflict) {
            $notes[] = self::NOTE_CURFEW_CONFLICT;
            $curfewNote = self::wording($sections[self::CURFEW_LABEL] ?? []);
            $partyNote = self::wording($sections[self::PARTY_LABEL] ?? []);
            $hasCurfew = null;
            $curfewAt = null;
        } else {
            $hasCurfew ??= $partyHasCurfew;
            $curfewAt ??= $partyCurfewAt;
        }

        // The writer dumps in this order, so it is fixed: setting, building, suitable_for.
        $main['suitable_for'] = 'wedding';
        $mainTerms = array_filter(['setting' => $main['setting'] ?? null, 'building' => $main['building'] ?? null, 'suitable_for' => $main['suitable_for']]);

        $terms = [];
        foreach (TermGroup::cases() as $group) {
            if ([] !== ($codes[$group->value] ?? [])) {
                $list = array_keys($codes[$group->value]);
                sort($list);
                $terms[$group->value] = $list;
            }
        }

        return new OwnerFacts(
            $priceLevel,
            $seated,
            $standing,
            $partyAllowed,
            $hasCurfew,
            true === $hasCurfew ? $curfewAt : null,
            isset($codes['amenity']['external_caterers']) ? OwnCatering::Allowed : null,
            $terms,
            $mainTerms,
            self::nullIfEmpty($ambience),
            self::fit($capacityNote, self::NOTE_CAPACITY_NOTE_CUT, $notes),
            self::fit(implode(' · ', array_filter([$curfewNote, $partyNote])), self::NOTE_PARTY_NOTE_CUT, $notes),
            $openingHours,
            self::fit(implode(', ', $parking), self::NOTE_PARKING_TEXT_CUT, $notes),
            array_values(array_unique($notes)),
            array_filter(array_map(static fn (array $tokens): array => array_values(array_unique($tokens)), $unmapped)),
            $spotCheck,
        );
    }

    /**
     * Setting words of `Ambiente` (PD11): a closed list of stems, on Unicode words, so a named
     * lake counts and `Seele` does not.
     *
     * @return list<string> sorted codes
     */
    private static function setting(string $ambience): array
    {
        $text = mb_strtolower((string) preg_replace('/\s+/u', ' ', $ambience));
        $words = preg_split('/[^\p{L}]+/u', $text, -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        $found = [];
        foreach ($words as $word) {
            if (\in_array($word, self::LAKE_WORDS, true) || (mb_strlen($word) >= self::LAKE_NAME_MIN_LENGTH && str_ends_with($word, 'see'))) {
                $found['lake'] = true;
            }
            if (1 === preg_match('/^(?:alpen|alpin|voralpin|berg)/u', $word)) {
                $found['mountains'] = true;
            }
            if (str_starts_with($word, 'urban')) {
                $found['city'] = true;
            }
            if (str_starts_with($word, 'ländlich')) {
                $found['countryside'] = true;
            }
        }
        if (1 === preg_match('/(?<!\p{L})im grünen(?!\p{L})/u', $text)) {
            $found['countryside'] = true;
        }

        $codes = array_keys($found);
        sort($codes);

        return $codes;
    }

    /**
     * Splits on the separators outside parentheses (D3), strips a leading bullet and folds.
     *
     * @param list<string> $separators
     *
     * @return list<array{string, string}> the token as written (trimmed) and folded; empty ones dropped
     */
    private static function tokens(string $paragraph, array $separators): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $chars = mb_str_split($paragraph);
        $count = \count($chars);
        for ($i = 0; $i < $count; ++$i) {
            $char = $chars[$i];
            if (0 === $depth) {
                foreach ($separators as $separator) {
                    $length = mb_strlen($separator);
                    if (mb_strtolower(implode('', \array_slice($chars, $i, $length))) === $separator) {
                        $parts[] = $current;
                        $current = '';
                        $i += $length - 1;
                        continue 2;
                    }
                }
            }
            if ('(' === $char) {
                ++$depth;
            } elseif (')' === $char && $depth > 0) {
                --$depth;
            }
            $current .= $char;
        }
        $parts[] = $current;

        $tokens = [];
        foreach ($parts as $part) {
            $original = (string) preg_replace('/^[\s\-•–]+|\s+$/u', '', $part);
            $folded = LegacyVenueText::fold($original);
            if ('' !== $folded) {
                $tokens[] = [$original, $folded];
            }
        }

        return $tokens;
    }

    /**
     * @return list<array{string, string}>
     */
    private static function languages(string $paragraph): array
    {
        $tokens = [];
        foreach (preg_split(self::LANGUAGE_SEPARATORS, $paragraph) ?: [] as $part) {
            $original = (string) preg_replace('/^[\s()→]+|[\s()→]+$/u', '', $part);
            $folded = LegacyVenueText::fold($original);
            if ('' !== $folded) {
                $tokens[] = [$original, $folded];
            }
        }

        return $tokens;
    }

    /**
     * @param list<array<string, string>> $tables
     */
    private static function lookup(string $token, array $tables): ?string
    {
        foreach ($tables as $table) {
            if (isset($table[$token])) {
                return $table[$token];
            }
        }

        return null;
    }

    /**
     * The paragraphs of the first of these labels the text holds.
     *
     * @param array<string, list<string>> $sections
     * @param list<string>                $labels
     *
     * @return list<string>
     */
    private static function section(array $sections, array $labels): array
    {
        foreach ($labels as $label) {
            if (isset($sections[$label])) {
                return $sections[$label];
            }
        }

        return [];
    }

    /**
     * D6: only a section of one paragraph gives a number. Plain `N Personen` needs no note;
     * any other wording is kept as the note, with or without a number.
     *
     * @param list<string> $paragraphs
     * @param list<string> $notes
     *
     * @return array{?int, ?string} the number and the wording for capacity_note
     */
    private static function capacity(array $paragraphs, string $outOfRangeNote, array &$notes): array
    {
        $wording = self::wording($paragraphs);
        if (null === $wording) {
            return [null, null];
        }
        if (1 !== \count($paragraphs)) {
            return [null, $wording];
        }

        $folded = LegacyVenueText::fold($wording);
        if (1 !== preg_match(self::CAPACITY, $folded, $match)) {
            return [null, $wording];
        }
        $low = self::number($match[1]);
        $high = isset($match[2]) ? self::number($match[2]) : $low;
        if ($high < $low || $high < 1 || $high > self::CAPACITY_MAX) {
            if ($high > self::CAPACITY_MAX || $high < 1) {
                $notes[] = $outOfRangeNote;
            }

            return [null, $wording];
        }

        return [$high, 1 === preg_match(self::PLAIN_CAPACITY, $folded) ? null : $wording];
    }

    private static function number(string $digits): int
    {
        return (int) str_replace(["'", '’'], '', $digits);
    }

    /**
     * `Sperrstunde` of the older form (D5). It never sets `party`: its `Nein` means no curfew.
     *
     * @param list<string> $paragraphs
     *
     * @return array{?bool, ?string, ?string} has_curfew, curfew_at, the wording for party_note
     */
    private static function curfew(array $paragraphs): array
    {
        $wording = self::wording($paragraphs);
        if (null === $wording) {
            return [null, null, null];
        }
        if (1 !== \count($paragraphs)) {
            return [null, null, $wording];
        }

        $folded = LegacyVenueText::fold($wording);
        if (\in_array($folded, self::NO_CURFEW, true)) {
            return [false, null, null];
        }
        if ('ja' === $folded) {
            return [true, null, null];
        }
        if (1 === preg_match(self::CURFEW_TIME, $folded, $match)) {
            $time = self::time($match[1], $match[2] ?? '');
            if (null !== $time) {
                return [true, $time[0], $time[1] ? $wording : null];
            }
        }

        return [null, null, $wording];
    }

    /**
     * `Party möglich?` of the newer form (D5, PD7): the answer, then what follows it.
     *
     * @param list<string> $paragraphs
     *
     * @return array{?PartyAllowed, ?bool, ?string, ?string} party, has_curfew, curfew_at, the wording for party_note
     */
    private static function party(array $paragraphs): array
    {
        $wording = self::wording($paragraphs);
        if (null === $wording) {
            return [null, null, null, null];
        }
        if (1 !== \count($paragraphs)) {
            return [null, null, null, $wording];
        }

        $folded = LegacyVenueText::fold($wording);
        if (1 !== preg_match(self::PARTY_PREFIX, $folded, $match)) {
            return [null, null, null, $wording];
        }
        $allowed = self::PARTY_ALLOWED[$match[1]];
        $rest = (string) preg_replace('/^[\s,\-–]+/u', '', $match[2]);

        if ('' === $rest) {
            return [$allowed, null, null, null];
        }
        if (\in_array($rest, self::PARTY_NO_CURFEW, true)) {
            return [$allowed, false, null, null];
        }
        if (1 === preg_match(self::PARTY_REST_TIME, $rest, $time) && null !== ($at = self::time($time[1], $time[2] ?? ''))) {
            return [$allowed, true, $at[0], $at[1] ? $wording : null];
        }

        return [$allowed, null, null, $wording];
    }

    /**
     * `H`, `H.MM` or `H:MM` as `HH:MM`. 24 is midnight and only with `00`; it keeps the
     * wording, because `'00:00'` alone reads as the start of the day (#31's Outcome).
     *
     * @return ?array{string, bool} the time and whether it was written as hour 24
     */
    private static function time(string $hour, string $minute): ?array
    {
        $h = (int) $hour;
        $m = '' === $minute ? 0 : (int) $minute;
        if ($m > 59 || $h > 24 || (24 === $h && 0 !== $m)) {
            return null;
        }

        return [\sprintf('%02d:%02d', 24 === $h ? 0 : $h, $m), 24 === $h];
    }

    /**
     * @param list<string> $paragraphs
     */
    private static function wording(array $paragraphs): ?string
    {
        return self::nullIfEmpty(trim(implode("\n", $paragraphs)));
    }

    /**
     * A note above 250 characters is cut at the last space before 249 and gets `…`.
     *
     * @param list<string> $notes
     */
    private static function fit(string $text, string $cutNote, array &$notes): ?string
    {
        if (mb_strlen($text) <= self::NOTE_MAX_LENGTH) {
            return self::nullIfEmpty($text);
        }
        $notes[] = $cutNote;
        $head = mb_substr($text, 0, self::NOTE_MAX_LENGTH - 1);
        $space = max((int) mb_strrpos($head, ' '), (int) mb_strrpos($head, "\n"));

        return rtrim(0 === $space ? $head : mb_substr($head, 0, $space)).'…';
    }

    private static function text(string $value): string
    {
        $normalized = \Normalizer::normalize($value, \Normalizer::NFC);

        return trim(false === $normalized ? $value : $normalized);
    }

    private static function nullIfEmpty(string $text): ?string
    {
        return '' === $text ? null : $text;
    }
}
