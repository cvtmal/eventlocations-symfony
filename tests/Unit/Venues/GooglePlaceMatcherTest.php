<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\GooglePlaceMatcher;
use App\Venues\ReadModel\GooglePlaceLookupVenue;
use App\Venues\ValueObject\GooglePlaceCandidate;
use App\Venues\ValueObject\GooglePlaceReason;
use App\Venues\ValueObject\GooglePlaceVerdict;
use App\Venues\VenueGeocoder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * The place ID lookup's decision (#57): one case per reason, fictional names only.
 */
final class GooglePlaceMatcherTest extends TestCase
{
    /** Google's area names for a place in the fictional town of Tannau. */
    private const array TANNAU = ['Tannau', 'Bezirk Uster', 'Zürich', 'Schweiz'];

    public function test_a_town_is_not_a_venue(): void
    {
        $verdict = self::decide(self::venue('Kupferkessel'), self::place('Kupferkessel', types: ['locality', 'political']));

        self::assertSame(GooglePlaceReason::NotAVenue, $verdict->reason);
        self::assertSame(GooglePlaceReason::NotAVenue, self::decide(self::venue('Kupferkessel'), self::place('Kupferkessel', types: ['sublocality_level_1']))->reason);
        self::assertSame(GooglePlaceReason::NotAVenue, self::decide(self::venue('Kupferkessel'), self::place('Kupferkessel', types: ['administrative_area_level_2']))->reason);
    }

    public function test_a_premise_may_be_a_venue(): void
    {
        self::assertTrue(self::decide(self::venue('Kupferkessel'), self::place('Kupferkessel', types: ['premise']))->confident());
    }

    public function test_a_permanently_closed_place_is_doubtful(): void
    {
        self::assertSame(GooglePlaceReason::Closed, self::decide(self::venue('Kupferkessel'), self::place('Kupferkessel', status: 'CLOSED_PERMANENTLY'))->reason);
    }

    public function test_a_temporarily_closed_place_can_match(): void
    {
        self::assertTrue(self::decide(self::venue('Kupferkessel'), self::place('Kupferkessel', status: 'CLOSED_TEMPORARILY'))->confident());
    }

    public function test_a_name_of_generic_words_only_is_doubtful(): void
    {
        self::assertSame(GooglePlaceReason::GenericName, self::decide(self::venue('Der Eventgasthof'), self::place('Eventgasthof'))->reason);
        self::assertSame(GooglePlaceReason::GenericName, self::decide(self::venue('Eventraum'), self::place('Eventraum'))->reason);
        self::assertSame(GooglePlaceReason::GenericName, self::decide(self::venue('Hotel & Restaurant AG'), self::place('Hotel Restaurant'))->reason);
    }

    public function test_a_venue_without_canton_or_region_is_doubtful(): void
    {
        self::assertSame(GooglePlaceReason::NoPlace, self::decide(self::venue('Kupferkessel', canton: ''), self::place('Kupferkessel'))->reason);
    }

    public function test_a_missing_word_of_ours_is_named(): void
    {
        $verdict = self::decide(self::venue('Kupferkessel Rosenau'), self::place('Restaurant Kupferkessel'));

        self::assertSame(GooglePlaceReason::NameDiffers, $verdict->reason);
        self::assertSame(['rosenau'], $verdict->words);
    }

    public function test_an_extra_word_of_google_is_named(): void
    {
        $verdict = self::decide(self::venue('Kupferkessel'), self::place('Kupferkessel Rosenau Lounge'));

        self::assertSame(GooglePlaceReason::GoogleNameLonger, $verdict->reason);
        self::assertSame(['rosenau'], $verdict->words);

        // A Google word inside our compound does not make our word known: "arena" does not stand for "wiesenarena".
        $compound = self::decide(self::venue('Wiesenarena'), self::place('Tannenplan Arena'));
        self::assertSame(GooglePlaceReason::NameDiffers, $compound->reason);
        self::assertSame(['wiesenarena'], $compound->words);
    }

    public function test_a_common_name_needs_a_place_word(): void
    {
        self::assertSame(GooglePlaceReason::CommonName, self::decide(self::venue('Restaurant Sternen'), self::place('Sternen'))->reason);
        self::assertSame(GooglePlaceReason::CommonName, self::decide(self::venue('Gasthof Bären'), self::place('Bären'))->reason);
        self::assertTrue(self::decide(self::venue('Sternen Wiesental'), self::place('Sternen', area: ['Wiesental', 'Bezirk Uster', 'Zürich', 'Schweiz']))->confident());
    }

    public function test_a_common_name_needs_googles_town_not_its_canton_or_district(): void
    {
        // Our town shares its name with the canton or district, and Google's inn of that name lies in another town.
        foreach ([
            ['Gasthof Sonne Zürich', 'zh', self::place('Sonne', area: self::TANNAU)],
            ['Hotel Krone Bern', 'be', self::place('Krone', canton: 'be', area: ['Aarwil', 'Verwaltungskreis Thun', 'Bern', 'Schweiz'])],
            ['Bären Zürich', 'zh', self::place('Hotel Bären Tannau', area: self::TANNAU)],
            ['Sternen Uster', 'zh', self::place('Sternen', area: self::TANNAU)],
            // The same holds for a name of the place word alone: Tannau's `Hotel Zürich` is not the one in Zürich.
            ['Hotel Zürich', 'zh', self::place('HOTEL ZÜRICH', area: self::TANNAU)],
        ] as [$ours, $canton, $place]) {
            $verdict = self::decide(self::venue($ours, canton: $canton), $place);
            self::assertSame(GooglePlaceReason::CommonName, $verdict->reason, $ours.' / '.$place->name);
            self::assertFalse($verdict->confident());
        }

        // The same inn words with their own town still match, also when Google names it as a quarter of a larger town.
        self::assertTrue(self::decide(self::venue('Gasthof Sonne Tannau'), self::place('Sonne', area: self::TANNAU))->confident());
        self::assertTrue(self::decide(self::venue('Hotel Krone Bern', canton: 'be'), self::place('Krone', canton: 'be', area: ['Bern', 'Verwaltungskreis Bern-Mittelland', 'Schweiz']))->confident());
        self::assertTrue(self::decide(self::venue('Sternen Uster'), self::place('Sternen'))->confident());
        self::assertTrue(self::decide(self::venue('Bären Tannau'), self::place('Bären', area: ['Tannau', 'Uster', 'Bezirk Uster', 'Zürich', 'Schweiz'], towns: ['Tannau', 'Uster']))->confident());
    }

    public function test_a_common_name_needs_googles_whole_town_not_a_town_that_contains_ours(): void
    {
        // Google's town merely contains our town word: one word of a longer name, a substring, or one edit apart.
        foreach ([
            ['Hotel Krone Bern', 'be', 'Krone', ['Aarwil bei Bern', 'Bern', 'Schweiz']],
            ['Gasthof Sonne St. Gallen', 'sg', 'Sonne', ['St. Gallenau', 'Wahlkreis See', 'St. Gallen', 'Schweiz']],
            ['Gasthof Sonne Tannau', 'zh', 'Sonne', ['Tannauschwil', 'Bezirk Uster', 'Zürich', 'Schweiz']],
            ['Gasthof Löwen Wiesenau', 'zh', 'Löwen', ['Wiesenauried', 'Bezirk Uster', 'Zürich', 'Schweiz']],
            ['Krone Tannau', 'zh', 'Krone', ['Tannan', 'Bezirk Uster', 'Zürich', 'Schweiz']],
            // One part of a hyphenated town is not the town: `X-près-Y`, `Ried-Brig`, `Martigny-Croix` shapes.
            ['Hôtel Bellevue Tannau', 'vd', 'Hôtel Bellevue', ['Aarwil-près-Tannau', 'Vaud', 'Schweiz']],
            ['Hotel Sonne Tannau', 'vs', 'Hotel Sonne', ['Ried-Tannau', 'Brig', 'Valais', 'Schweiz']],
            ['Hôtel Bellevue Tannau', 'vs', 'Hôtel Bellevue', ['Tannau-Croix', 'Valais', 'Schweiz']],
            ['Gasthof Sonne Aarwil', 'zh', 'Sonne', ['Aarwil-Tannau', 'Bezirk Uster', 'Zürich', 'Schweiz']],
            ['Gasthof Sonne Tannau', 'zh', 'Sonne', ['Saint-Tannau', 'Bezirk Uster', 'Zürich', 'Schweiz']],
            // A town named like a stop or generic word is no proof: `Hotel au Lac` does not name the town `Au`.
            ['Hôtel Au Lac', 'sg', 'Hotel au Lac', ['Au', 'Wahlkreis Rheintal', 'St. Gallen', 'Schweiz']],
            ['Gasthof Krone zur Burg', 'ag', 'Gasthof Krone', ['Burg', 'Bezirk Kulm', 'Aargau', 'Schweiz']],
        ] as [$ours, $canton, $theirs, $area]) {
            $verdict = self::decide(self::venue($ours, canton: $canton), self::place($theirs, canton: $canton, area: $area));
            self::assertSame(GooglePlaceReason::CommonName, $verdict->reason, $ours.' / '.$area[0]);
            self::assertFalse($verdict->confident());
        }
    }

    public function test_a_common_name_matches_googles_whole_town(): void
    {
        self::assertTrue(self::decide(self::venue('Gasthof Sonne Tannau'), self::place('Sonne', area: self::TANNAU))->confident());
        // A town of several words, `St.` included, must stand in our name as a whole.
        self::assertTrue(self::decide(self::venue('Krone St. Gallen', canton: 'sg'), self::place('Krone', canton: 'sg', area: ['St. Gallen', 'Wahlkreis St. Gallen', 'St. Gallen', 'Schweiz']))->confident());
        self::assertTrue(self::decide(self::venue('Hotel Krone Aarwil bei Bern', canton: 'be'), self::place('Krone', canton: 'be', area: ['Aarwil bei Bern', 'Bern', 'Schweiz']))->confident());
        // A hyphenated town counts only as a whole.
        self::assertTrue(self::decide(self::venue('Gasthof Sonne Aarwil-Tannau'), self::place('Sonne', area: ['Aarwil-Tannau', 'Bezirk Uster', 'Zürich', 'Schweiz']))->confident());
        // A generic word in the town is fine next to a word that names it.
        self::assertTrue(self::decide(self::venue('Gasthof Sonne Bad Tannau'), self::place('Sonne', area: ['Bad Tannau', 'Bezirk Uster', 'Zürich', 'Schweiz']))->confident());
    }

    public function test_french_and_italian_inn_words_are_weak_like_the_german_ones(): void
    {
        $poste = self::decide(self::venue('Hôtel de la Poste Tannau', canton: 'vd'), self::place('Hôtel de la Poste', canton: 'vd', area: ['Aarwil-près-Tannau', 'Vaud', 'Schweiz']));
        self::assertSame(GooglePlaceReason::CommonName, $poste->reason);
        $posta = self::decide(self::venue('Albergo della Posta Tannau', canton: 'ti'), self::place('Albergo della Posta', canton: 'ti', area: ['Aarwil', 'Distretto di Tannau', 'Ticino', 'Svizzera']));
        self::assertSame(GooglePlaceReason::CommonName, $posta->reason);

        // In their own town they still match.
        self::assertTrue(self::decide(self::venue('Hôtel de la Poste Tannau', canton: 'vd'), self::place('Hôtel de la Poste', canton: 'vd', area: ['Tannau', 'Vaud', 'Schweiz']))->confident());
        self::assertTrue(self::decide(self::venue('Albergo della Posta Tannau', canton: 'ti'), self::place('Albergo della Posta', canton: 'ti', area: ['Tannau', 'Ticino', 'Svizzera']))->confident());
    }

    public function test_a_missing_canton_is_doubtful(): void
    {
        self::assertSame(GooglePlaceReason::CantonUnknown, self::decide(self::venue('Kupferkessel'), self::place('Kupferkessel', canton: ''))->reason);
    }

    public function test_another_canton_or_country_is_doubtful(): void
    {
        $canton = self::decide(self::venue('Kupferkessel'), self::place('Kupferkessel', canton: 'sg', area: ['Wil', 'St. Gallen', 'Schweiz']));
        self::assertSame(GooglePlaceReason::PlaceDiffers, $canton->reason);
        self::assertSame('Wil, St. Gallen, Schweiz', $canton->area);

        self::assertSame(GooglePlaceReason::PlaceDiffers, self::decide(self::venue('Kupferkessel'), self::place('Kupferkessel', country: 'LI'))->reason);
    }

    public function test_a_liechtenstein_venue_needs_liechtenstein_only(): void
    {
        self::assertTrue(self::decide(self::venue('Kupferkessel', canton: 'li'), self::place('Kupferkessel', canton: '', country: 'LI', area: ['Vaduz', 'Liechtenstein']))->confident());
        self::assertSame(GooglePlaceReason::PlaceDiffers, self::decide(self::venue('Kupferkessel', canton: 'li'), self::place('Kupferkessel', canton: 'sg', country: 'CH'))->reason);
    }

    public function test_a_region_must_be_named_by_google(): void
    {
        $area = ['Liestal', 'Bezirk Liestal', 'Basel-Landschaft', 'Schweiz'];

        self::assertTrue(self::decide(self::venue('Kupferkessel', canton: '', region: 'Basel'), self::place('Kupferkessel', canton: 'bl', area: $area))->confident());
        self::assertSame(GooglePlaceReason::PlaceDiffers, self::decide(self::venue('Kupferkessel', canton: '', region: 'Schweizweit'), self::place('Kupferkessel', canton: 'bl', area: $area))->reason);
    }

    public function test_a_place_far_from_our_pin_is_doubtful(): void
    {
        // One degree of latitude is 111.195 km: 0.0126° is 1.4 km, 0.0072° is 0.8 km.
        $venue = self::venue('Kupferkessel', lat: 47.0, lng: 8.5);

        $far = self::decide($venue, self::place('Kupferkessel', lat: 47.0126, lng: 8.5));
        self::assertSame(GooglePlaceReason::FarFromPin, $far->reason);
        self::assertSame(1.4, $far->km);

        self::assertTrue(self::decide($venue, self::place('Kupferkessel', lat: 47.0072, lng: 8.5))->confident());
    }

    public function test_no_pin_means_no_distance_veto(): void
    {
        self::assertTrue(self::decide(self::venue('Kupferkessel'), self::place('Kupferkessel', lat: 46.2, lng: 9.9))->confident());
    }

    public function test_a_second_fitting_place_makes_it_ambiguous(): void
    {
        $venue = self::venue('Kupferkessel');
        $top = self::place('Kupferkessel');

        self::assertSame(GooglePlaceReason::Ambiguous, GooglePlaceMatcher::decide($venue, [$top, self::place('Kupferkessel', id: 'ChIJPlace0000002')])->reason);
        self::assertTrue(GooglePlaceMatcher::decide($venue, [$top, self::place('Kupferkessel')])->confident(), 'the same place twice');
        self::assertTrue(GooglePlaceMatcher::decide($venue, [$top, self::place('Rosenau', id: 'ChIJPlace0000002')])->confident(), 'a second place with another name');
    }

    public function test_a_clear_match_is_confident(): void
    {
        $verdict = self::decide(self::venue('Landgasthof Kupferkessel'), self::place('Kupferkessel'));

        self::assertSame(GooglePlaceReason::Match, $verdict->reason);
        self::assertTrue($verdict->confident());
        self::assertSame('confident', $verdict->reason->confidence());
    }

    public function test_names_match_across_umlauts_accents_and_apostrophes(): void
    {
        self::assertTrue(self::decide(self::venue('Bärengraben'), self::place('Baerengraben'))->confident());
        self::assertTrue(self::decide(self::venue('Café Lindenblatt'), self::place('Cafe Lindenblatt'))->confident());
        self::assertTrue(self::decide(self::venue('OX’n Tannenberg'), self::place("OX'n Tannenberg"))->confident());
        // A decomposed umlaut (u + combining diaeresis) reads like the composed one.
        self::assertTrue(self::decide(self::venue("Mu\u{0308}hlrad Tannenberg"), self::place('Mühlrad Tannenberg'))->confident());
    }

    public function test_joined_names_skip_the_word_checks(): void
    {
        self::assertTrue(self::decide(self::venue('Halle 7'), self::place('HALLE7'))->confident());
    }

    public function test_one_edit_counts_only_for_long_words(): void
    {
        self::assertTrue(self::decide(self::venue('Tannenhain'), self::place('Tannenhein'))->confident());

        $short = self::decide(self::venue('Kolm Tannenberg'), self::place('Kalm Tannenberg'));
        self::assertSame(GooglePlaceReason::NameDiffers, $short->reason);
        self::assertSame(['kolm'], $short->words);
    }

    public function test_our_long_word_inside_a_google_word_counts(): void
    {
        self::assertTrue(self::decide(self::venue('Kupferkessel'), self::place('Kupferkesselch'))->confident());
    }

    public function test_a_weak_word_is_never_found_inside_a_longer_word(): void
    {
        $linde = self::decide(self::venue('Gasthof Linde Tannau'), self::place('Hotel Lindengarten', area: self::TANNAU));
        self::assertSame(GooglePlaceReason::NameDiffers, $linde->reason);
        self::assertSame(['linde'], $linde->words);

        $sonne = self::decide(self::venue('Restaurant Sonne Tannau'), self::place('Sonnenberg', area: self::TANNAU));
        self::assertSame(GooglePlaceReason::NameDiffers, $sonne->reason);
        self::assertSame(['sonne'], $sonne->words);

        // Inflected forms start the same compounds: `linden` does not stand for `lindenhof` either.
        foreach ([
            ['Gasthof Linden Tannau', 'Hotel Lindenhof', 'linden'],
            ['Restaurant Rosen Tannau', 'Rosengarten', 'rosen'],
            ['Gasthaus Kronen Tannau', 'Kronenhof', 'kronen'],
        ] as [$ours, $theirs, $word]) {
            $inflected = self::decide(self::venue($ours), self::place($theirs, area: self::TANNAU));
            self::assertSame(GooglePlaceReason::NameDiffers, $inflected->reason, $ours.' / '.$theirs);
            self::assertSame([$word], $inflected->words);
        }

        // The other direction: a weak word of Google's inside ours, or our word inside a weak word of Google's.
        $extra = self::decide(self::venue('Sonnenhalde'), self::place('Gasthof Sonne Sonnenhalde'));
        self::assertSame(GooglePlaceReason::GoogleNameLonger, $extra->reason);
        self::assertSame(['sonne'], $extra->words);
        $inside = self::decide(self::venue('Alpen Tannau'), self::place('Alpenblick', area: self::TANNAU));
        self::assertSame(GooglePlaceReason::NameDiffers, $inside->reason);
        self::assertSame(['alpen'], $inside->words);
    }

    public function test_a_weak_word_is_still_found_when_equal_or_one_edit_apart(): void
    {
        self::assertTrue(self::decide(self::venue('Gasthof Linde Tannau'), self::place('Linde', area: self::TANNAU))->confident());
        self::assertTrue(self::decide(self::venue('Gasthof Schwanen Tannau'), self::place('Schwannen', area: self::TANNAU))->confident());
    }

    public function test_a_name_of_place_words_only_needs_googles_whole_name(): void
    {
        foreach ([
            ['Schloss Tannau', 'Hotel Tannau'],
            ['Restaurant Tannau', 'Schloss Tannau'],
            ['Landgasthof Tannau', 'Tannau'],
            ['Hotel Zürich', 'Hotel Zürichwald'],
        ] as [$ours, $theirs]) {
            $verdict = self::decide(self::venue($ours), self::place($theirs, area: self::TANNAU));
            self::assertSame(GooglePlaceReason::PlaceNameOnly, $verdict->reason, $ours.' / '.$theirs);
            self::assertFalse($verdict->confident());
        }

        // Our region's words are place words too, even when Google's area does not name them.
        self::assertSame(GooglePlaceReason::PlaceNameOnly, self::decide(self::venue('Haus Emmental', canton: '', region: 'Emmental'), self::place('Hotel Emmental', canton: 'be', area: ['Langnau', 'Bern', 'Schweiz']))->reason);
    }

    public function test_a_name_of_place_words_only_matches_googles_identical_name(): void
    {
        self::assertTrue(self::decide(self::venue('Schloss Tannau'), self::place('Schloss Tannau', area: self::TANNAU))->confident());
        self::assertTrue(self::decide(self::venue('Hotel Zürich'), self::place('HOTEL ZÜRICH', area: ['Zürich', 'Bezirk Zürich', 'Schweiz']))->confident());
        // A strong word of its own makes the name more than a place: the type word may differ.
        self::assertTrue(self::decide(self::venue('Kupferkessel Tannau'), self::place('Restaurant Kupferkessel', area: self::TANNAU))->confident());
    }

    public function test_street_words_never_count_as_address_words(): void
    {
        $client = new MockHttpClient(new JsonMockResponse(['places' => [[
            'id' => 'ChIJPlace0000001',
            'displayName' => ['text' => 'Kupferkessel'],
            'formattedAddress' => 'Rosenau 3, 8610 Uster',
            'location' => ['latitude' => 47.35, 'longitude' => 8.72],
            'types' => ['event_venue', 'establishment'],
            'addressComponents' => [
                ['longText' => '3', 'shortText' => '3', 'types' => ['street_number']],
                ['longText' => 'Rosenau', 'shortText' => 'Rosenau', 'types' => ['route']],
                ['longText' => '8610', 'shortText' => '8610', 'types' => ['postal_code']],
                ['longText' => 'Uster', 'shortText' => 'Uster', 'types' => ['locality', 'political']],
                ['longText' => 'Zürich', 'shortText' => 'ZH', 'types' => ['administrative_area_level_1', 'political']],
                ['longText' => 'Schweiz', 'shortText' => 'CH', 'types' => ['country', 'political']],
            ],
        ]]]));
        $candidates = (new VenueGeocoder($client, 'k'))->candidates('Kupferkessel Rosenau Zürich Schweiz');
        \assert([] !== $candidates);

        $verdict = GooglePlaceMatcher::decide(self::venue('Kupferkessel Rosenau'), $candidates);

        self::assertSame(GooglePlaceReason::NameDiffers, $verdict->reason);
        self::assertSame(['rosenau'], $verdict->words);
        self::assertTrue(GooglePlaceMatcher::decide(self::venue('Kupferkessel Uster'), $candidates)->confident(), 'the town is an address word');
    }

    public function test_distance_km_is_the_great_circle(): void
    {
        self::assertEqualsWithDelta(111.195, GooglePlaceMatcher::distanceKm(46.0, 8.0, 47.0, 8.0), 0.001);
        self::assertSame(0.0, GooglePlaceMatcher::distanceKm(47.0, 8.5, 47.0, 8.5));
    }

    public function test_the_generic_and_weak_lists_are_the_calibrated_ones(): void
    {
        self::assertSame([
            'hotel', 'hotels', 'restaurant', 'restaurants', 'ristorante', 'gasthof', 'gasthaus', 'landgasthof',
            'berggasthaus', 'berggasthof', 'gaststube', 'wirtschaft', 'beiz', 'schloss', 'burg', 'villa', 'haus', 'hof',
            'landhaus', 'seehotel', 'parkhotel', 'grand', 'resort', 'spa', 'lodge', 'bad', 'event', 'events',
            'eventlocation', 'eventlokal', 'eventraum', 'eventhalle', 'location', 'loc', 'locations', 'lokal', 'saal',
            'raum', 'bankett', 'festsaal', 'seminar', 'seminare', 'seminarhotel', 'kongress', 'zentrum', 'center',
            'centre', 'catering', 'kultur', 'bar', 'lounge', 'cafe', 'kafi', 'bistro', 'auberge', 'albergo',
            'der', 'die', 'das', 'dem', 'den', 'des', 'am', 'im', 'zum', 'zur', 'zu', 'und', 'an', 'auf', 'bei', 'in',
            'ob', 'von', 'vom', 'mit', 'the', 'and', 'of', 'at', 'by', 'la', 'le', 'les', 'du', 'de', 'et', 'au',
            'aux', 'en', 'sur', 'chez', 'il', 'lo', 'della', 'di', 'del', 'al',
            'ag', 'gmbh', 'sa', 'sarl', 'stiftung', 'schweiz', 'suisse', 'svizzera', 'switzerland',
        ], GooglePlaceMatcher::GENERIC);
        self::assertSame([
            'baeren', 'loewen', 'ochsen', 'kreuz', 'sonne', 'sternen', 'hirschen', 'roessli', 'adler', 'krone', 'linde',
            'engel', 'schwanen', 'rebstock', 'traube', 'falken', 'frohsinn', 'freihof', 'schaefli', 'steinbock', 'waage',
            'eintracht', 'harmonie', 'rose', 'seeblick', 'seehof', 'bahnhof', 'post', 'rathaus', 'paradies', 'panorama',
            'belvedere', 'bellevue', 'alpenblick', 'schuetzen', 'sonnenberg', 'central', 'national', 'schweizerhof',
            'lac', 'park', 'tell', 'muehle', 'scheune', 'linden', 'rosen', 'kronen', 'sonnen', 'trauben',
            'ours', 'orso', 'lion', 'leone', 'boeuf', 'bue', 'croix', 'croce', 'soleil', 'sole', 'etoile', 'stella',
            'cerf', 'cervo', 'cheval', 'blanc', 'cavallo', 'bianco', 'aigle', 'aquila', 'couronne', 'corona',
            'tilleul', 'tiglio', 'ange', 'angelo', 'cygne', 'cigno', 'vigne', 'raisin', 'uva', 'faucon', 'falco',
            'mouton', 'bouquetin', 'stambecco', 'balance', 'bilancia', 'concorde', 'concordia', 'armonia', 'rosa',
            'gare', 'stazione', 'poste', 'posta', 'ville', 'municipio', 'paradis', 'paradiso', 'bellavista',
            'centrale', 'nazionale', 'lago', 'parc', 'parco', 'moulin', 'mulino', 'grange',
        ], GooglePlaceMatcher::WEAK);
        self::assertSame(1.0, GooglePlaceMatcher::MAX_PIN_KM);
    }

    /** The decision on a single candidate. */
    private static function decide(GooglePlaceLookupVenue $venue, GooglePlaceCandidate $place): GooglePlaceVerdict
    {
        return GooglePlaceMatcher::decide($venue, [$place]);
    }

    private static function venue(string $name, string $canton = 'zh', string $region = '', ?float $lat = null, ?float $lng = null): GooglePlaceLookupVenue
    {
        return new GooglePlaceLookupVenue(7, $name, $canton, $region, $lat, $lng, true);
    }

    /**
     * The town is the first area name unless given, as in Google's answer (town, district, canton, country).
     *
     * @param list<string>      $types
     * @param list<string>      $area
     * @param list<string>|null $towns
     */
    private static function place(
        string $name,
        string $id = 'ChIJPlace0000001',
        array $types = ['event_venue', 'point_of_interest', 'establishment'],
        string $canton = 'zh',
        string $country = 'CH',
        string $status = 'OPERATIONAL',
        array $area = ['Uster', 'Bezirk Uster', 'Zürich', 'Schweiz'],
        float $lat = 47.35,
        float $lng = 8.72,
        ?array $towns = null,
    ): GooglePlaceCandidate {
        return new GooglePlaceCandidate($id, $name, 'Wiesenweg 4, 8610 Uster', $lat, $lng, $types, $canton, $country, $status, $area, $towns ?? \array_slice($area, 0, 1));
    }
}
