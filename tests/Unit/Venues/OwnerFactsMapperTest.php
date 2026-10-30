<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\OwnerFactsMapper;
use App\Venues\ValueObject\Content\OwnerFacts;
use App\Venues\ValueObject\OwnCatering;
use App\Venues\ValueObject\PartyAllowed;
use App\Venues\ValueObject\PriceLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every value here is invented in the shape of the owner's form; none comes from the CSV.
 */
final class OwnerFactsMapperTest extends TestCase
{
    public function test_a_row_without_sections(): void
    {
        $facts = self::map();

        self::assertSame(PriceLevel::Medium, $facts->priceLevel);
        self::assertSame(['building' => ['hotel'], 'suitable_for' => ['wedding']], $facts->terms);
        self::assertSame(['building' => 'hotel', 'suitable_for' => 'wedding'], $facts->mainTerms);
        self::assertSame('Gemütlich', $facts->ambience);
        self::assertNull($facts->seated);
        self::assertNull($facts->standing);
        self::assertNull($facts->partyAllowed);
        self::assertNull($facts->hasCurfew);
        self::assertNull($facts->curfewAt);
        self::assertNull($facts->ownCatering);
        self::assertNull($facts->capacityNote);
        self::assertNull($facts->partyNote);
        self::assertNull($facts->openingHours);
        self::assertNull($facts->parkingText);
        self::assertSame([], $facts->notes);
        self::assertSame([], $facts->unmapped);
        self::assertFalse($facts->spotCheck);
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function types(): iterable
    {
        yield 'Originelle Location' => ['Originelle Location', ['experience_creative'], 'experience_creative'];
        yield 'Hotel' => ['Hotel', ['hotel'], 'hotel'];
        yield 'Restaurant' => ['Restaurant', ['restaurant'], 'restaurant'];
        yield 'Gasthof' => ['Gasthof', ['restaurant'], 'restaurant'];
        yield 'Burg & Schloss' => ['Burg & Schloss', ['castle'], 'castle'];
        yield 'Saal' => ['Saal', ['event_hall'], 'event_hall'];
        yield 'Eventhalle' => ['Eventhalle', ['event_hall'], 'event_hall'];
        yield 'Gutshof & Weingut' => ['Gutshof & Weingut', ['brewery_winery'], 'brewery_winery'];
        yield 'Scheune' => ['Scheune', ['farm_barn'], 'farm_barn'];
        yield 'Hotel Restaurant' => ['Hotel Restaurant', ['hotel', 'restaurant'], 'hotel'];
        yield 'Kultur-Gästehaus' => ['Kultur-Gästehaus', ['cultural_centre'], 'cultural_centre'];
        yield 'Waldhaus' => ['Waldhaus', ['forest_hut'], 'forest_hut'];
    }

    /**
     * @param list<string> $buildings
     */
    #[DataProvider('types')]
    public function test_every_typ_maps_to_a_building(string $type, array $buildings, string $main): void
    {
        $facts = self::map(row: ['Typ' => $type]);

        self::assertSame($buildings, $facts->terms['building']);
        self::assertSame($main, $facts->mainTerms['building']);
        self::assertSame(['wedding'], $facts->terms['suitable_for']);
        self::assertSame('wedding', $facts->mainTerms['suitable_for']);
        self::assertSame([], $facts->notes);
    }

    public function test_an_unknown_typ_or_preisniveau_is_absent_and_noted(): void
    {
        $facts = self::map(row: ['Typ' => 'Raumschiff', 'Preisniveau' => 'Günstig']);

        self::assertArrayNotHasKey('building', $facts->terms);
        self::assertArrayNotHasKey('building', $facts->mainTerms);
        self::assertNull($facts->priceLevel);
        self::assertSame([OwnerFactsMapper::NOTE_PRICE_LEVEL_UNMAPPED, OwnerFactsMapper::NOTE_TYPE_UNMAPPED], $facts->notes);
        self::assertSame(['preisniveau' => ['Günstig'], 'typ' => ['Raumschiff']], $facts->unmapped);
    }

    /**
     * @return iterable<string, array{string, PriceLevel}>
     */
    public static function priceLevels(): iterable
    {
        yield 'Moderat' => ['Moderat', PriceLevel::Moderate];
        yield 'Mittel' => ['Mittel', PriceLevel::Medium];
        yield 'Exklusiv' => ['Exklusiv', PriceLevel::Exclusive];
    }

    #[DataProvider('priceLevels')]
    public function test_every_preisniveau_maps(string $value, PriceLevel $level): void
    {
        self::assertSame($level, self::map(row: ['Preisniveau' => $value])->priceLevel);
    }

    /**
     * @return iterable<string, array{string, list<string>, ?string}>
     */
    public static function settings(): iterable
    {
        yield 'See' => ['Romantisch am See', ['lake'], 'lake'];
        yield 'Seeblick' => ['Mit Seeblick', ['lake'], 'lake'];
        yield 'Seenähe' => ['Ruhig in Seenähe', ['lake'], 'lake'];
        yield 'a named lake' => ['Direkt am Beispielersee', ['lake'], 'lake'];
        yield 'Seele is no lake' => ['Ein Ort mit Seele', [], null];
        yield 'Alpen' => ['Alpenpanorama pur', ['mountains'], 'mountains'];
        yield 'Berg' => ['Mit Bergsicht', ['mountains'], 'mountains'];
        yield 'voralpin' => ['Voralpine Ruhe', ['mountains'], 'mountains'];
        yield 'urban' => ['Urban und modern', ['city'], 'city'];
        yield 'ländlich' => ['Ländliche Idylle', ['countryside'], 'countryside'];
        yield 'im Grünen' => ['Mitten im Grünen', ['countryside'], 'countryside'];
        yield 'two settings, no main' => ['Urban mit Seesicht', ['city', 'lake'], null];
        yield 'nothing named' => ['Elegant und festlich', [], null];
    }

    /**
     * @param list<string> $codes
     */
    #[DataProvider('settings')]
    public function test_setting_from_ambiente(string $ambience, array $codes, ?string $main): void
    {
        $facts = self::map(row: ['Ambiente' => $ambience]);

        self::assertSame($codes, $facts->terms['setting'] ?? []);
        self::assertSame($main, $facts->mainTerms['setting'] ?? null);
        self::assertSame($ambience, $facts->ambience);
    }

    public function test_main_terms_keep_their_order(): void
    {
        self::assertSame(['setting', 'building', 'suitable_for'], array_keys(self::map(row: ['Ambiente' => 'Am See'])->mainTerms));
    }

    public function test_an_ambiente_above_60_characters_is_left_out(): void
    {
        $facts = self::map(row: ['Ambiente' => str_repeat('Am See ', 10)]);

        self::assertNull($facts->ambience);
        self::assertSame(['lake'], $facts->terms['setting']);
        self::assertSame([OwnerFactsMapper::NOTE_AMBIENCE_TOO_LONG], $facts->notes);
    }

    public function test_opening_hours_are_verbatim_and_cut_above_250(): void
    {
        self::assertSame('Mai bis Oktober, täglich ab 10 Uhr', self::map(row: ['Öffnungszeiten' => ' Mai bis Oktober, täglich ab 10 Uhr '])->openingHours);

        $facts = self::map(row: ['Öffnungszeiten' => str_repeat('Montag bis Freitag ', 20)]);
        self::assertNotNull($facts->openingHours);
        self::assertLessThanOrEqual(250, mb_strlen($facts->openingHours));
        self::assertStringEndsWith('Freitag…', $facts->openingHours);
        self::assertSame([OwnerFactsMapper::NOTE_OPENING_HOURS_CUT], $facts->notes);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function amenityTokens(): iterable
    {
        $tokens = [
            'Tanzfläche' => 'amenity.dance_floor',
            'Terrasse' => 'surroundings.terrace',
            'Rollstuhlgängig' => 'amenity.wheelchair_access',
            'Behindertengerechte Infrastruktur' => 'amenity.wheelchair_access',
            'Behindertengerechte Infrastruktur usw.' => 'amenity.wheelchair_access',
            'Barrierefreie Infrastruktur' => 'amenity.wheelchair_access',
            'Barrierefreier Zugang' => 'amenity.wheelchair_access',
            'Barrierefrei' => 'amenity.wheelchair_access',
            'Eigene Küche' => 'amenity.own_kitchen',
            'Garten' => 'surroundings.garden',
            'Essen im Freien' => 'amenity.outdoor_dining',
            'Empfang bzw. Bankett im Freien möglich' => 'amenity.outdoor_dining',
            'Bankett im Freien möglich' => 'amenity.outdoor_dining',
            'Panoramablick' => 'amenity.panorama',
            'Parkplätze' => 'amenity.parking',
            'Genügend Parkplätze' => 'amenity.parking',
            'Parkplätze genügend' => 'amenity.parking',
            'Parkplätze genügend vorhanden' => 'amenity.parking',
            'Parkplätze vorhanden' => 'amenity.parking',
            'Parkplätze (genügend)' => 'amenity.parking',
            'Parkplätze (Anzahl, genügend)' => 'amenity.parking',
            'Grosser Parkplatz' => 'amenity.parking',
            'Kostenlose Parkplätze' => 'amenity.parking',
            'Gratis Parkplätze' => 'amenity.parking',
            'Viele Parkplätze' => 'amenity.parking',
            'Parkbereich' => 'amenity.parking_area',
            'Übernachtung' => 'amenity.overnight',
            'Übernachtungsmöglichkeiten' => 'amenity.overnight',
            'Beamer' => 'technology.projector_screen',
            'Leinwand' => 'technology.projector_screen',
            'Beamer und Leinwand' => 'technology.projector_screen',
            'Seeblick' => 'amenity.lake_view',
            'Seesicht' => 'amenity.lake_view',
            'Musikanlage' => 'technology.sound_system',
            'Musik-Technik' => 'technology.sound_system',
            'Kinderraum' => 'amenity.kids_room',
            'ÖV Anbindung' => 'amenity.public_transport',
            'Zapfengeld' => 'amenity.corkage',
            'Catering möglich' => 'amenity.catering_available',
            'Zelt' => 'amenity.tent',
            'Externe Caterer erlaubt' => 'amenity.external_caterers',
            'Bühne' => 'infrastructure.stage',
            'Spielplatz' => 'surroundings.playground',
        ];
        foreach (['Einrichtung', 'Infrastruktur'] as $section) {
            foreach ($tokens as $token => $code) {
                yield $section.': '.$token => [$section, $token, $code];
            }
        }
    }

    #[DataProvider('amenityTokens')]
    public function test_every_amenity_token_maps(string $section, string $token, string $code): void
    {
        $facts = self::map([$section => ['- '.$token]]);

        [$group, $value] = explode('.', $code);
        self::assertSame([$value], $facts->terms[$group]);
        self::assertSame([], $facts->unmapped);
        self::assertNull($facts->parkingText);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function parkingCounts(): iterable
    {
        foreach (['25 Parkplätze', '25Parkplätze', 'ca. 25 Parkplätze', 'Parkplätze 25', 'Parkplätze (25)', 'Parkplätze (Anzahl 25)', 'Parkplätze (Anzahl: 25)', 'Parkplätze (ca. 25)', 'Parkplätze (über 25)', '25 Parkplätze (gratis)', '25Parkplätze (gratis)'] as $token) {
            yield $token => [$token];
        }
    }

    #[DataProvider('parkingCounts')]
    public function test_a_parking_count_sets_parking_and_its_text(string $token): void
    {
        $facts = self::map(['Infrastruktur' => ["Garten\n".$token]]);

        self::assertSame(['parking'], $facts->terms['amenity']);
        self::assertSame($token, $facts->parkingText);
        self::assertSame([], $facts->unmapped);
    }

    public function test_external_caterers_allow_own_catering(): void
    {
        $facts = self::map(['Infrastruktur' => ['Externe Caterer erlaubt, Zelt']]);

        self::assertSame(['external_caterers', 'tent'], $facts->terms['amenity']);
        self::assertSame(OwnCatering::Allowed, $facts->ownCatering);
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function ceremonyTokens(): iterable
    {
        yield 'Zeremonie: civil' => ['Zeremonie', 'Zivile Trauung möglich', 'civil_ceremony', false];
        yield 'Zeremonie: free' => ['Zeremonie', 'Freie Trauung möglich', 'free_ceremony', false];
        yield 'Zeremonie: free rooms' => ['Zeremonie', 'Räumlichkeiten für freie Trauung', 'free_ceremony', false];
        yield 'Zeremonie: church' => ['Zeremonie', 'Kirche in Gehdistanz', 'church_nearby', false];
        yield 'Zeremonie: rain' => ['Zeremonie', 'Schlechtwetter-Alternative vorhanden', 'rain_alternative', false];
        yield 'Einrichtung: D7 spot-check' => ['Einrichtung', 'Räumlichkeiten für standesamtliche Hochzeiten', 'civil_ceremony', true];
        yield 'Einrichtung: free' => ['Einrichtung', 'Freie Trauung möglich', 'free_ceremony', false];
        yield 'Einrichtung: free rooms' => ['Einrichtung', 'Räumlichkeiten für freie Trauung', 'free_ceremony', false];
        yield 'Einrichtung: registry office' => ['Einrichtung', 'Offizielles Standesamt-Lokal', 'registry_office', false];
        yield 'Einrichtung: chapel' => ['Einrichtung', 'Kapelle', 'chapel', false];
    }

    #[DataProvider('ceremonyTokens')]
    public function test_every_ceremony_token_maps(string $section, string $token, string $code, bool $spotCheck): void
    {
        $facts = self::map([$section => [$token]]);

        self::assertSame([$code], $facts->terms['ceremony']);
        self::assertSame($spotCheck, $facts->spotCheck);
        self::assertSame([], $facts->unmapped);
    }

    public function test_the_older_civil_option_is_not_read_in_the_newer_section(): void
    {
        $facts = self::map(['Zeremonie' => ['Räumlichkeiten für standesamtliche Hochzeiten']]);

        self::assertArrayNotHasKey('ceremony', $facts->terms);
        self::assertFalse($facts->spotCheck);
        self::assertSame(['zeremonie' => ['Räumlichkeiten für standesamtliche Hochzeiten']], $facts->unmapped);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function diningTokens(): iterable
    {
        $tokens = [
            'Vegetarisch' => 'vegetarian', 'Vegetarische' => 'vegetarian', 'Vegan' => 'vegan', 'Glutenfrei' => 'gluten_free',
            'Allergiker' => 'allergy_friendly', 'Kindermenu' => 'kids_menu', 'Kindermenü' => 'kids_menu', 'Diabetiker' => 'diabetic',
            'Hochzeitstorte' => 'wedding_cake', 'Torte' => 'wedding_cake', 'Bio' => 'organic', 'Buffet' => 'buffet', 'Grill' => 'grill',
            'Alles möglich' => 'anything_possible', 'Halal' => 'halal', 'Koscher' => 'kosher',
        ];
        foreach (['Essen', 'Essen / Trinken'] as $section) {
            foreach ($tokens as $token => $code) {
                yield $section.': '.$token => [$section, $token, $code];
            }
        }
    }

    #[DataProvider('diningTokens')]
    public function test_every_dining_token_maps(string $section, string $token, string $code): void
    {
        $facts = self::map([$section => [$token]]);

        self::assertSame([$code], $facts->terms['dining']);
        self::assertSame([], $facts->unmapped);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function vendorTokens(): iterable
    {
        $tokens = [
            'Dekoservice' => 'flowers_decor', 'Dekoration' => 'flowers_decor', 'Floristen' => 'flowers_decor', 'Floristin' => 'flowers_decor',
            'Florist' => 'flowers_decor', 'Blumen/Deko' => 'flowers_decor', 'Fotograf' => 'photography', 'Fotografen' => 'photography',
            'Fotografie' => 'photography', 'Musiker' => 'musicians', 'Musikern' => 'musicians', 'Mobiliar' => 'furniture',
            'Mobiliarvermietung' => 'furniture', 'Mobiliarvermietung (Hussen, Tische, Stühle, Partyzelt)' => 'furniture',
            'Videograf' => 'videography', 'Trauredner' => 'officiant', 'DJ' => 'dj', 'DJs' => 'dj', 'Weddingplanner' => 'wedding_planner',
        ];
        foreach (['Service', 'Zusammenarbeit Dienstleister?'] as $section) {
            foreach ($tokens as $token => $code) {
                yield $section.': '.$token => [$section, $token, $code];
            }
        }
    }

    #[DataProvider('vendorTokens')]
    public function test_every_vendor_token_maps(string $section, string $token, string $code): void
    {
        $facts = self::map([$section => [$token]]);

        self::assertSame([$code], $facts->terms['vendor']);
        self::assertSame([], $facts->unmapped);
    }

    public function test_d3_a_parenthesised_list_is_one_token(): void
    {
        $facts = self::map(['Service' => ['Mobiliarvermietung (Hussen, Tische, Stühle, Partyzelt), DJ']]);

        self::assertSame(['dj', 'furniture'], $facts->terms['vendor']);
        self::assertArrayNotHasKey('furniture', $facts->terms);
    }

    public function test_d4_a_generic_option_with_examples_sets_nothing(): void
    {
        $facts = self::map(['Einrichtung' => ["Technische Installationen (wie Beamer, Musikanlage, Bühne usw.)\nSanitäre Einrichtungen"]]);

        self::assertArrayNotHasKey('technology', $facts->terms);
        self::assertArrayNotHasKey('infrastructure', $facts->terms);
        self::assertSame(['einrichtung' => ['Technische Installationen (wie Beamer, Musikanlage, Bühne usw.)', 'Sanitäre Einrichtungen']], $facts->unmapped);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function servicePrompts(): iterable
    {
        yield 'the full prompt' => ['Intern oder Zusammenarbeit mit externen Dienstleistern wie Caterer, Weddingplanner, Dekoservice, Mobiliarvermietung (Hussen, Tische, Stühle, Partyzelt), Musiker, Fotograf, Videograf, Trauredner'];
        yield 'another lead-in' => ['Zusammenarbeit mit: Weddingplanner, Dekoservice, Mobiliarvermietung (Hussen, Tische, Stühle), Musiker, Fotograf, Videograf, Trauredner usw.'];
        yield 'collapsed whitespace' => ["Wir vermitteln Weddingplanner,  Dekoservice, Mobiliarvermietung (Hussen, Tische, Stühle, Partyzelt), Musiker, Fotograf,\u{00A0}Videograf, Trauredner"];
    }

    #[DataProvider('servicePrompts')]
    public function test_pd5_the_echoed_service_prompt_sets_no_vendor(string $paragraph): void
    {
        $facts = self::map(['Service' => [$paragraph]]);

        self::assertArrayNotHasKey('vendor', $facts->terms);
        self::assertSame([], $facts->unmapped);
        self::assertSame([OwnerFactsMapper::NOTE_SERVICE_PROMPT], $facts->notes);
    }

    public function test_pd5_a_real_selection_of_those_words_counts(): void
    {
        $facts = self::map(['Service' => ['Weddingplanner, Fotograf, Trauredner']]);

        self::assertSame(['officiant', 'photography', 'wedding_planner'], $facts->terms['vendor']);
        self::assertSame([], $facts->notes);
    }

    /**
     * @return iterable<string, array{string, list<string>, bool}>
     */
    public static function usages(): iterable
    {
        yield 'Exklusiv' => ['Exklusiv', ['exclusive'], false];
        yield 'Immer exklusiv' => ['Immer exklusiv', ['exclusive'], false];
        yield 'with a list in parentheses' => ['Exklusiv (nur eine Hochzeit, eine Feier, ein Seminar)', ['exclusive'], false];
        yield 'oder in parentheses' => ['Exklusiv (nur eine Hochzeit oder ein Event gleichzeitig möglich)', ['exclusive'], false];
        yield 'colon' => ['Exklusiv: nur eine Hochzeit', ['exclusive'], false];
        yield 'same day' => ['Immer exklusiv mit nur einer Hochzeit am selben Tag', ['exclusive'], false];
        yield 'nicht exklusiv' => ['Nicht exklusiv (mehrere Veranstaltungen gleichzeitig möglich)', ['multiple_weddings'], false];
        yield 'nicht exklusive' => ['Nicht exklusive', ['multiple_weddings'], false];
        yield 'singular typo' => ['Mehrere Veranstaltung gleichzeitig möglich', ['multiple_weddings'], false];
        yield 'Mehrere Hochzeiten' => ['Mehrere Hochzeiten', ['multiple_weddings'], false];
        yield 'Hochzeit + Normalbetrieb' => ['Hochzeit + Normalbetrieb', ['wedding_and_regular'], false];
        yield 'two options with oder' => ['Exklusiv oder nicht exklusiv', ['exclusive', 'multiple_weddings'], false];
        yield 'two options with und' => ['Hochzeit + Normalbetrieb und Mehrere Hochzeiten', ['multiple_weddings', 'wedding_and_regular'], false];
        yield 'prose' => ['Nach Absprache mit der Gastgeberin', [], true];
    }

    /**
     * @param list<string> $codes
     */
    #[DataProvider('usages')]
    public function test_usage(string $text, array $codes, bool $prose): void
    {
        $facts = self::map(['Nutzung' => [$text]]);

        self::assertSame($codes, $facts->terms['usage'] ?? []);
        self::assertSame($prose ? [OwnerFactsMapper::NOTE_USAGE_PROSE] : [], $facts->notes);
        self::assertSame($prose ? ['nutzung' => [$text]] : [], $facts->unmapped);
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function languages(): iterable
    {
        yield 'words and separators' => ['Deutsch, Englisch / Französisch und Italienisch', ['de', 'en', 'fr', 'it'], []];
        yield 'letters' => ['D. E. F.', ['de', 'en', 'fr'], []];
        yield 'codes' => ['DE | EN & FR; I', ['de', 'en', 'fr', 'it'], []];
        yield 'english word and typo' => ["English\nFranzösich", ['en', 'fr'], []];
        yield 'Swiss German' => ['Schweizerdeutsch, CH-Deutsch, CH', ['gsw'], []];
        yield 'rare' => ['Spanisch, Portugiesisch, Niederländisch, Tschechisch, Russisch, Ungarisch', ['cs', 'es', 'hu', 'nl', 'pt', 'ru'], []];
        yield 'Romanian and its typo' => ['Rumänisch, Rümanisch', ['ro'], []];
        yield 'brackets and arrows are trimmed' => ['→ Deutsch, (Englisch)', ['de', 'en'], []];
        yield 'qualified forms stay unmapped' => ['Deutsch, Englisch (teilweise), eventuell Französisch, Thai', ['de'], ['Englisch (teilweise', 'eventuell Französisch', 'Thai']];
    }

    /**
     * @param list<string> $codes
     * @param list<string> $unmapped
     */
    #[DataProvider('languages')]
    public function test_languages(string $text, array $codes, array $unmapped): void
    {
        $facts = self::map(['Sprachen, die vom Personal gesprochen werden:' => [$text]]);

        self::assertSame($codes, $facts->terms['language']);
        self::assertSame([] === $unmapped ? [] : ['sprachen, die vom personal gesprochen werden' => $unmapped], $facts->unmapped);
    }

    /**
     * @return iterable<string, array{string, ?int, bool}>
     */
    public static function capacities(): iterable
    {
        yield 'plain' => ['120 Personen', 120, false];
        yield 'max.' => ['max. 120 Personen', 120, true];
        yield 'max without a dot' => ['max 100 Personen', 100, true];
        yield 'no space before the unit' => ['400Personen', 400, true];
        yield 'bis zu' => ['bis zu 120 Gäste', 120, true];
        yield 'ca. without a unit' => ['ca. 90', 90, true];
        yield 'Swiss apostrophe' => ["1'200 Gäste", 1200, true];
        yield 'typographic apostrophe' => ['1’200 Personen', 1200, true];
        yield 'Pers.' => ['80 Pers.', 80, true];
        yield 'range' => ['80-120 Personen', 120, true];
        yield 'en dash range' => ['80 – 120 Pax', 120, true];
        yield 'bis range' => ['80 bis 120 Personen', 120, true];
        yield 'two numbers' => ['120 drinnen, 80 draussen', null, true];
        yield 'no number' => ['Je nach Bestuhlung', null, true];
        yield 'out of range' => ['60000 Personen', null, true];
    }

    #[DataProvider('capacities')]
    public function test_d6_capacity(string $text, ?int $number, bool $note): void
    {
        $seated = self::map(['Kapazität für gesetztes Essen' => [$text]]);
        $standing = self::map(['Kapazität für Stehempfang / Apéro' => [$text]]);

        self::assertSame($number, $seated->seated);
        self::assertSame($number, $standing->standing);
        self::assertSame($note ? 'Gesetztes Essen: '.$text : null, $seated->capacityNote);
        self::assertSame($note ? 'Apéro: '.$text : null, $standing->capacityNote);
    }

    public function test_d6_out_of_range_is_noted(): void
    {
        self::assertSame([OwnerFactsMapper::NOTE_SEATED_OUT_OF_RANGE], self::map(['Kapazität für gesetztes Essen' => ['60000 Personen']])->notes);
    }

    public function test_d6_several_paragraphs_give_a_note_only(): void
    {
        $facts = self::map(['Kapazität für gesetztes Essen' => ['120 Personen', '80 Personen im Keller']]);

        self::assertNull($facts->seated);
        self::assertSame("Gesetztes Essen: 120 Personen\n80 Personen im Keller", $facts->capacityNote);
    }

    public function test_pd6_both_wordings_join_with_labels(): void
    {
        $facts = self::map([
            'Kapazität für gesetztes Essen' => ['max. 100 Personen'],
            'Kapazität für Stehempfang/Apéro' => ['ca. 200'],
        ]);

        self::assertSame([100, 200], [$facts->seated, $facts->standing]);
        self::assertSame('Gesetztes Essen: max. 100 Personen · Apéro: ca. 200', $facts->capacityNote);

        $plainSeated = self::map([
            'Kapazität für gesetztes Essen' => ['100 Personen'],
            'Kapazität für Stehempfang' => ['ca. 200'],
        ]);
        self::assertSame('Apéro: ca. 200', $plainSeated->capacityNote);
    }

    public function test_a_long_note_is_cut_at_a_word(): void
    {
        $facts = self::map(['Kapazität für gesetztes Essen' => [str_repeat('Im Saal oder im Garten ', 15)]]);

        self::assertNotNull($facts->capacityNote);
        self::assertLessThanOrEqual(250, mb_strlen($facts->capacityNote));
        self::assertStringEndsWith('…', $facts->capacityNote);
        self::assertStringEndsNotWith(' …', $facts->capacityNote);
        self::assertSame([OwnerFactsMapper::NOTE_CAPACITY_NOTE_CUT], $facts->notes);
    }

    public function test_a_multi_room_capacity_section_is_noted(): void
    {
        $facts = self::map(['Kapazitäten' => ['Saal 100, Keller 40']]);

        self::assertNull($facts->seated);
        self::assertSame([OwnerFactsMapper::NOTE_CAPACITY_SECTION], $facts->notes);
    }

    /**
     * @return iterable<string, array{string, ?bool, ?string, bool}>
     */
    public static function curfews(): iterable
    {
        yield 'Nein' => ['Nein', false, null, false];
        yield 'Keine' => ['Keine', false, null, false];
        yield 'Keine Sperrstunde' => ['Keine Sperrstunde', false, null, false];
        yield 'Ohne Sperrstunde' => ['Ohne Sperrstunde.', false, null, false];
        yield 'Nein, keine bei Hochzeiten' => ['Nein, keine bei Hochzeiten', false, null, false];
        yield 'Ja' => ['Ja', true, null, false];
        yield 'bis H Uhr' => ['Bis 2 Uhr', true, '02:00', false];
        yield 'ja, ab H.MM Uhr morgens' => ['Ja, ab 1.30 Uhr morgens', true, '01:30', false];
        yield 'um H:MM h' => ['um 3:00 h', true, '03:00', false];
        yield 'Hh' => ['2h', true, '02:00', false];
        yield 'nachts Sperrstunde' => ['Ja 1 Uhr nachts Sperrstunde', true, '01:00', false];
        yield 'midnight as 00.00' => ['00.00 Uhr', true, '00:00', false];
        yield 'midnight as 24 keeps the note' => ['24 Uhr', true, '00:00', true];
        yield 'midnight as 24.00 keeps the note' => ['24.00 Uhr', true, '00:00', true];
        yield 'a no with a but' => ['Nein, jedoch normal bis 2 Uhr', null, null, true];
        yield 'hour 25' => ['25 Uhr', null, null, true];
        yield '24:30' => ['24:30', null, null, true];
        yield 'two times' => ['Freitag bis 2 Uhr, Samstag bis 3 Uhr', null, null, true];
    }

    #[DataProvider('curfews')]
    public function test_d5_sperrstunde(string $text, ?bool $hasCurfew, ?string $curfewAt, bool $note): void
    {
        $facts = self::map(['Sperrstunde:' => [$text]]);

        self::assertSame($hasCurfew, $facts->hasCurfew);
        self::assertSame($curfewAt, $facts->curfewAt);
        self::assertSame($note ? $text : null, $facts->partyNote);
        self::assertNull($facts->partyAllowed, 'Sperrstunde never sets party');
    }

    /**
     * @return iterable<string, array{string, ?PartyAllowed, ?bool, ?string, bool}>
     */
    public static function parties(): iterable
    {
        yield 'Ja' => ['Ja', PartyAllowed::Yes, null, null, false];
        yield 'Mit Einschränkung' => ['Mit Einschränkung', PartyAllowed::Limited, null, null, false];
        yield 'Nein' => ['Nein', PartyAllowed::No, null, null, false];
        yield 'ja, ohne Sperrstunde' => ['Ja, ohne Sperrstunde', PartyAllowed::Yes, false, null, false];
        yield 'limited - keine Sperrstunde' => ['Mit Einschränkung - keine Sperrstunde', PartyAllowed::Limited, false, null, false];
        yield 'ja, bis H Uhr' => ['Ja, bis 2 Uhr', PartyAllowed::Yes, true, '02:00', false];
        yield 'ja – H.MM' => ['Ja – 1.30', PartyAllowed::Yes, true, '01:30', false];
        yield 'ja, bis 24 Uhr keeps the note' => ['Ja, bis 24 Uhr', PartyAllowed::Yes, true, '00:00', true];
        yield 'ja with a condition' => ['Ja, aber nur im Saal', PartyAllowed::Yes, null, null, true];
        yield 'no answer word' => ['Vielleicht', null, null, null, true];
        yield 'jahrelang is no yes' => ['Jahrelang erprobt', null, null, null, true];
    }

    #[DataProvider('parties')]
    public function test_d5_party(string $text, ?PartyAllowed $party, ?bool $hasCurfew, ?string $curfewAt, bool $note): void
    {
        $facts = self::map(['Party möglich?' => [$text]]);

        self::assertSame($party, $facts->partyAllowed);
        self::assertSame($hasCurfew, $facts->hasCurfew);
        self::assertSame($curfewAt, $facts->curfewAt);
        self::assertSame($note ? $text : null, $facts->partyNote);
    }

    public function test_pd7_both_wordings_join(): void
    {
        $facts = self::map(['Sperrstunde' => ['Freitag bis 2 Uhr'], 'Party möglich?' => ['Ja, aber leise']]);

        self::assertSame('Freitag bis 2 Uhr · Ja, aber leise', $facts->partyNote);
        self::assertSame(PartyAllowed::Yes, $facts->partyAllowed);
        self::assertNull($facts->hasCurfew);
    }

    public function test_pd7_agreeing_fields_combine(): void
    {
        $facts = self::map(['Sperrstunde' => ['Ja'], 'Party möglich?' => ['Ja, bis 1 Uhr']]);

        self::assertTrue($facts->hasCurfew);
        self::assertSame('01:00', $facts->curfewAt);
        self::assertNull($facts->partyNote);
    }

    public function test_pd7_a_conflict_sets_no_curfew(): void
    {
        $facts = self::map(['Sperrstunde' => ['Nein'], 'Party möglich?' => ['Ja, bis 2 Uhr']]);

        self::assertSame(PartyAllowed::Yes, $facts->partyAllowed);
        self::assertNull($facts->hasCurfew);
        self::assertNull($facts->curfewAt);
        self::assertSame('Nein · Ja, bis 2 Uhr', $facts->partyNote);
        self::assertSame([OwnerFactsMapper::NOTE_CURFEW_CONFLICT], $facts->notes);
    }

    public function test_a_long_party_note_is_cut(): void
    {
        $facts = self::map(['Sperrstunde' => [str_repeat('Nach Absprache mit dem Haus ', 12)]]);

        self::assertNotNull($facts->partyNote);
        self::assertLessThanOrEqual(250, mb_strlen($facts->partyNote));
        self::assertSame([OwnerFactsMapper::NOTE_PARTY_NOTE_CUT], $facts->notes);
    }

    /**
     * The newer form's fixed options must all map: the "zero unmapped" proof of `### 32.`.
     */
    public function test_every_newer_form_option_maps(): void
    {
        $facts = self::map([
            'Nutzung' => ['Hochzeit + Normalbetrieb, Immer exklusiv, Mehrere Hochzeiten'],
            'Kapazität für gesetztes Essen' => ['120 Personen'],
            'Kapazität für Stehempfang / Apéro' => ['180 Personen'],
            'Infrastruktur' => [implode("\n", [
                'Tanzfläche', 'Terrasse', 'Rollstuhlgängig', 'Eigene Küche', 'Garten', 'Essen im Freien', 'Panoramablick',
                'Parkplätze', 'Parkbereich', 'Übernachtungsmöglichkeiten', 'Beamer', 'Leinwand', 'Seeblick', 'Musikanlage',
                'Kinderraum', 'ÖV Anbindung', 'Zapfengeld', 'Catering möglich', 'Zelt', 'Externe Caterer erlaubt', 'Bühne', 'Spielplatz',
            ])],
            'Essen' => ['Vegetarisch, Vegan, Glutenfrei, Allergiker, Diabetiker, Hochzeitstorte, Bio, Buffet, Grill, Alles möglich, Halal, Koscher'],
            'Party möglich?' => ['Ja, bis 2 Uhr'],
            'Zeremonie' => ['Zivile Trauung möglich, Freie Trauung möglich, Kirche in Gehdistanz, Schlechtwetter-Alternative vorhanden'],
            'Zusammenarbeit Dienstleister?' => ['Floristen, Fotograf, Musiker, Mobiliar, Trauredner, DJ'],
            'Sprachen die vom Personal gesprochen werden' => ['Deutsch, Englisch, Französisch, Italienisch'],
        ]);

        self::assertSame([], $facts->unmapped);
        self::assertSame([], $facts->notes);
        self::assertSame(['church_nearby', 'civil_ceremony', 'free_ceremony', 'rain_alternative'], $facts->terms['ceremony']);
        self::assertCount(12, $facts->terms['dining']);
        self::assertCount(6, $facts->terms['vendor']);
        self::assertCount(3, $facts->terms['usage']);
        self::assertSame([120, 180], [$facts->seated, $facts->standing]);
        self::assertSame([PartyAllowed::Yes, true, '02:00'], [$facts->partyAllowed, $facts->hasCurfew, $facts->curfewAt]);
        self::assertSame(OwnCatering::Allowed, $facts->ownCatering);
    }

    public function test_codes_are_sorted_and_groups_ordered(): void
    {
        $facts = self::map([
            'Essen / Trinken' => ['Vegan, Bio, Vegan'],
            'Einrichtung' => ['Terrasse, Tanzfläche, Garten, Beamer, Bühne, Kapelle'],
        ], ['Ambiente' => 'Am See']);

        self::assertSame(['setting', 'building', 'suitable_for', 'infrastructure', 'technology', 'surroundings', 'ceremony', 'amenity', 'dining'], array_keys($facts->terms));
        self::assertSame(['garden', 'terrace'], $facts->terms['surroundings']);
        self::assertSame(['organic', 'vegan'], $facts->terms['dining']);
    }

    public function test_tokens_split_on_newline_semicolon_and_strip_bullets(): void
    {
        $facts = self::map(['Einrichtung' => ["• Tanzfläche; – Garten\n- Zelt", 'Bar, Lift']]);

        self::assertSame(['dance_floor', 'tent'], $facts->terms['amenity']);
        self::assertSame(['garden'], $facts->terms['surroundings']);
        self::assertSame(['einrichtung' => ['Bar', 'Lift']], $facts->unmapped);
    }

    public function test_notes_never_quote_a_cell(): void
    {
        $facts = self::map([
            'Kapazität für gesetztes Essen' => ['Erfundene Zahl 99999'],
            'Sperrstunde' => ['Erfundene Regel'],
            'Nutzung' => ['Erfundene Nutzung'],
        ], ['Typ' => 'Erfundener Typ', 'Preisniveau' => 'Erfunden']);

        foreach ($facts->notes as $note) {
            self::assertStringNotContainsString('Erfunden', $note);
        }
    }

    /**
     * @param array<string, list<string>> $sections label as written to its paragraphs
     * @param array<string, string>       $row
     */
    private static function map(array $sections = [], array $row = []): OwnerFacts
    {
        $paragraphs = ['Beschrieb', 'Ein erfundener Text über einen erfundenen Ort.'];
        foreach ($sections as $label => $texts) {
            $paragraphs[] = $label;
            foreach ($texts as $text) {
                $paragraphs[] = $text;
            }
        }

        return (new OwnerFactsMapper())->map($row + [
            'Preisniveau' => 'Mittel',
            'Typ' => 'Hotel',
            'Ambiente' => 'Gemütlich',
            'Öffnungszeiten' => '',
        ], $paragraphs);
    }
}
