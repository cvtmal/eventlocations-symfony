<?php

declare(strict_types=1);

namespace App\Venues;

use App\Geo\ValueObject\Canton;
use App\Venues\ReadModel\GooglePlaceLookupVenue;
use App\Venues\ValueObject\GooglePlaceCandidate;
use App\Venues\ValueObject\GooglePlaceReason;
use App\Venues\ValueObject\GooglePlaceVerdict;

/**
 * Decides whether Google's top answer is our venue (#57): `match` only when every check passes, else the first
 * failing check's reason, in `GooglePlaceReason`'s order. Precision over coverage: a doubtful row goes to staff,
 * a wrong confident one would show another business's rating. Pure: no service, no database, no clock.
 *
 * The word lists were calibrated on the real venues (research #57 §Calibration) and are pinned by
 * `GooglePlaceMatcherTest`; when a confident match proves wrong, staff fix it in the CMS card and a word is added
 * here with a test. Distance to our pin is a veto only, never proof.
 *
 * Rules from the reviews (#57, F-M2, R2-M1, R3-M1 and R4-M1) keep another business out:
 * - a `WEAK` word is found only when equal or one edit apart, never inside a longer word, in either direction
 *   (`linde` does not stand for `lindenhof`, nor `sonne` for `sonnenberg`); `WEAK` holds the inflected forms too
 *   (`linden`, `sonnen`), since they start the same compounds;
 * - when every name word of ours is a place word (one of Google's area words, or one of our region's words), the
 *   name says only where, not who: Google's whole name, type words included, must equal ours, so
 *   `Schloss Tannau` is not `Hotel Tannau` (`place_name_only`). Short words (`am`, `AG`) do not count, as
 *   everywhere; stop words of 3 letters or more do, since nothing shows that `Zum Tannau` is `Tannau`;
 * - a name with no strong word (`common_name`) needs Google's whole town in it, not merely its area names
 *   (R2-M1, R3-M1, R4-M1, R4-m1): consecutive words of ours must equal one of Google's town names as a whole, and
 *   that town must have a word of 3 letters or more that is not generic. `Gasthof Sonne Zürich` names the town
 *   Zürich, and a `Sonne` elsewhere in the canton of Zürich is not it; `Krone Bern` is not a `Krone` in
 *   `Muri bei Bern`, nor `Sonne Wohlen` one in `Wohlenschwil`, nor `Sonne Brig` one in `Ried-Brig`; `Hotel au Lac`
 *   does not name the town `Au`. No substring, no one edit, no part of a longer or hyphenated town. The other checks
 *   still read the whole area (town, district, canton, country).
 */
final class GooglePlaceMatcher
{
    /** Generic venue and business words, stop words in de/fr/it/en, legal forms and the country: never a name word. */
    public const array GENERIC = [
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
    ];

    /**
     * Common Swiss inn and view names, with their inflected forms that start compounds, then their French and Italian
     * twins: they never prove a match alone.
     */
    public const array WEAK = [
        'baeren', 'loewen', 'ochsen', 'kreuz', 'sonne', 'sternen', 'hirschen', 'roessli', 'adler', 'krone', 'linde',
        'engel', 'schwanen', 'rebstock', 'traube', 'falken', 'frohsinn', 'freihof', 'schaefli', 'steinbock', 'waage',
        'eintracht', 'harmonie', 'rose', 'seeblick', 'seehof', 'bahnhof', 'post', 'rathaus', 'paradies', 'panorama',
        'belvedere', 'bellevue', 'alpenblick', 'schuetzen', 'sonnenberg', 'central', 'national', 'schweizerhof',
        'lac', 'park', 'tell', 'muehle', 'scheune', 'linden', 'rosen', 'kronen', 'sonnen', 'trauben',
        'ours', 'orso', 'lion', 'leone', 'boeuf', 'bue', 'croix', 'croce', 'soleil', 'sole', 'etoile', 'stella',
        'cerf', 'cervo', 'cheval', 'blanc', 'cavallo', 'bianco', 'aigle', 'aquila', 'couronne', 'corona', 'tilleul',
        'tiglio', 'ange', 'angelo', 'cygne', 'cigno', 'vigne', 'raisin', 'uva', 'faucon', 'falco', 'mouton',
        'bouquetin', 'stambecco', 'balance', 'bilancia', 'concorde', 'concordia', 'armonia', 'rosa', 'gare',
        'stazione', 'poste', 'posta', 'ville', 'municipio', 'paradis', 'paradiso', 'bellavista', 'centrale',
        'nazionale', 'lago', 'parc', 'parco', 'moulin', 'mulino', 'grange',
    ];

    /** A place of one of these types is a town, street or area, not a business; `premise` stays allowed. */
    public const array NOT_A_VENUE_TYPES = [
        'locality', 'political', 'postal_town', 'neighborhood', 'colloquial_area', 'country', 'route',
        'street_address', 'intersection', 'natural_feature', 'geocode', 'plus_code',
    ];

    /** Type prefixes with the same meaning (`postal_code_prefix`, `sublocality_level_1`, …). */
    public const array NOT_A_VENUE_TYPE_PREFIXES = ['postal_code', 'sublocality', 'administrative_area_level_'];

    /** Farther than this from our own pin, the candidate is doubted. */
    public const float MAX_PIN_KM = 1.0;

    private const float EARTH_RADIUS_KM = 6371.0;

    /**
     * @param non-empty-list<GooglePlaceCandidate> $candidates Google's answer in rank order; the finder handles none
     */
    public static function decide(GooglePlaceLookupVenue $venue, array $candidates): GooglePlaceVerdict
    {
        $top = $candidates[0];
        $failure = self::check($venue, $top);
        if (null !== $failure) {
            return $failure;
        }

        if (null !== $venue->lat && null !== $venue->lng) {
            $km = self::distanceKm($venue->lat, $venue->lng, $top->lat, $top->lng);
            if ($km > self::MAX_PIN_KM) {
                return GooglePlaceVerdict::doubtful(GooglePlaceReason::FarFromPin, km: round($km, 1));
            }
        }

        foreach (\array_slice($candidates, 1) as $other) {
            if ($other->id !== $top->id && null === self::check($venue, $other)) {
                return GooglePlaceVerdict::doubtful(GooglePlaceReason::Ambiguous);
            }
        }

        return GooglePlaceVerdict::match();
    }

    /** Great-circle distance (haversine), for the far-from-pin veto and the report's `distance_km`. */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }

    /**
     * Checks 1 to 7 on one candidate: null when it passes them all.
     */
    private static function check(GooglePlaceLookupVenue $venue, GooglePlaceCandidate $candidate): ?GooglePlaceVerdict
    {
        if (self::isNotAVenue($candidate->types)) {
            return GooglePlaceVerdict::doubtful(GooglePlaceReason::NotAVenue);
        }
        if ('CLOSED_PERMANENTLY' === $candidate->businessStatus) {
            return GooglePlaceVerdict::doubtful(GooglePlaceReason::Closed);
        }

        $ours = self::nameWords($venue->name);
        if ([] === $ours) {
            return GooglePlaceVerdict::doubtful(GooglePlaceReason::GenericName);
        }
        $canton = Canton::tryFrom($venue->cantonCode);
        if (null === $canton && '' === trim($venue->region)) {
            return GooglePlaceVerdict::doubtful(GooglePlaceReason::NoPlace);
        }

        $theirs = self::nameWords($candidate->name);
        $area = self::words(implode(' ', $candidate->areaNames));

        // "Halle 7" and "HALLE7" are the same name: the word checks would split them differently.
        if (implode('', $ours) !== implode('', $theirs)) {
            $missing = array_values(array_filter($ours, static fn (string $word): bool => !self::found($word, $theirs) && !self::found($word, $area)));
            if ([] !== $missing) {
                return GooglePlaceVerdict::doubtful(GooglePlaceReason::NameDiffers, $missing);
            }
            $extra = array_values(array_filter($theirs, static fn (string $word): bool => !self::foundEitherWay($word, $ours) && !self::found($word, $area)));
            if ([] !== $extra) {
                return GooglePlaceVerdict::doubtful(GooglePlaceReason::GoogleNameLonger, $extra);
            }
        }

        $region = self::words($venue->region);
        $placeOnly = [] === array_filter($ours, static fn (string $word): bool => !self::found($word, $area) && !self::found($word, $region));
        if ($placeOnly && implode('', self::words($venue->name)) !== implode('', self::words($candidate->name))) {
            return GooglePlaceVerdict::doubtful(GooglePlaceReason::PlaceNameOnly);
        }

        $strong = array_filter($ours, static fn (string $word): bool => !\in_array($word, self::WEAK, true) && !self::found($word, $area) && self::found($word, $theirs));
        if ([] === $strong && !self::namesTown($venue->name, $candidate->townNames)) {
            return GooglePlaceVerdict::doubtful(GooglePlaceReason::CommonName);
        }

        return self::placeCheck($venue, $canton, $candidate, $area);
    }

    /**
     * Check 7: a Swiss canton must be Google's canton in Switzerland, Liechtenstein must be Liechtenstein, and a
     * region must be named among Google's area names.
     *
     * @param list<string> $area
     */
    private static function placeCheck(GooglePlaceLookupVenue $venue, ?Canton $canton, GooglePlaceCandidate $candidate, array $area): ?GooglePlaceVerdict
    {
        $differs = GooglePlaceVerdict::doubtful(GooglePlaceReason::PlaceDiffers, area: implode(', ', $candidate->areaNames));

        if (null === $canton) {
            foreach (self::words($venue->region) as $word) {
                if (!self::found($word, $area)) {
                    return $differs;
                }
            }

            return null;
        }
        if ('CH' !== $canton->countryCode()) {
            return $canton->countryCode() === $candidate->countryCode ? null : $differs;
        }
        if ('' === $candidate->cantonCode) {
            return GooglePlaceVerdict::doubtful(GooglePlaceReason::CantonUnknown);
        }

        return $candidate->cantonCode === $canton->value && 'CH' === $candidate->countryCode ? null : $differs;
    }

    /**
     * @param list<string> $types
     */
    private static function isNotAVenue(array $types): bool
    {
        foreach ($types as $type) {
            if (\in_array($type, self::NOT_A_VENUE_TYPES, true)) {
                return true;
            }
            foreach (self::NOT_A_VENUE_TYPE_PREFIXES as $prefix) {
                if (str_starts_with($type, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The words of a name that can tell it apart: no generic word, nothing shorter than 3 letters unless it has a
     * digit.
     *
     * @return list<string>
     */
    private static function nameWords(string $name): array
    {
        return array_values(array_filter(self::words($name), static fn (string $word): bool => !self::isGeneric($word)));
    }

    /**
     * Check 6's place proof: consecutive words of our name, every word kept (`St.`, `bei`), equal one of Google's
     * town names as a whole, and that town has a word of 3 letters or more that is not generic. Only the town proves
     * it, since our town may also name its canton or district (review R2-M1); only the whole town, since
     * `Muri bei Bern`, `Wohlenschwil` or `Chavannes-près-Renens` is not Bern, Wohlen or Renens (R3-M1, R4-M1); and
     * not a town named like a stop or generic word, since `Hotel au Lac` does not name the town `Au` (R4-m1).
     *
     * @param list<string> $townNames
     */
    private static function namesTown(string $name, array $townNames): bool
    {
        $ours = ' '.implode(' ', self::tokens($name)).' ';
        foreach ($townNames as $town) {
            $words = self::tokens($town);
            $named = array_filter($words, static fn (string $word): bool => \strlen($word) >= 3 && !self::isGeneric($word));
            if ([] !== $named && str_contains($ours, ' '.implode(' ', $words).' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalised words of 3 letters or more, or with a digit, each once, in order.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $words = [];
        foreach (self::tokens($text) as $word) {
            if ((\strlen($word) >= 3 || 1 === preg_match('/\d/', $word)) && !\in_array($word, $words, true)) {
                $words[] = $word;
            }
        }

        return $words;
    }

    /**
     * Every normalised word, in order, none dropped: apostrophes dropped, umlauts folded to ae/oe/ue, accents
     * stripped, lower case, split at anything but a-z and 0-9.
     *
     * @return list<string>
     */
    private static function tokens(string $text): array
    {
        // Composed first, so a decomposed umlaut is folded to ae/oe/ue like a composed one.
        $text = strtr((string) \Normalizer::normalize($text, \Normalizer::FORM_C), [
            "'" => '', '’' => '', '`' => '', '´' => '',
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
        ]);
        $text = strtolower((string) preg_replace('/\p{Mn}/u', '', (string) \Normalizer::normalize($text, \Normalizer::FORM_KD)));

        return preg_split('/[^a-z0-9]+/', $text, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** A generic word, or two generic words of 3 letters or more glued together (`eventgasthof`). */
    private static function isGeneric(string $word): bool
    {
        if (\in_array($word, self::GENERIC, true)) {
            return true;
        }
        for ($i = 3, $end = \strlen($word) - 3; $i <= $end; ++$i) {
            if (\in_array(substr($word, 0, $i), self::GENERIC, true) && \in_array(substr($word, $i), self::GENERIC, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Equal; or both 6 letters or more and one edit apart; or the word, 5 letters or more, inside one of the list,
     * unless either is a `WEAK` word. A list word inside ours does not count.
     *
     * @param list<string> $list
     */
    private static function found(string $word, array $list): bool
    {
        $weak = \in_array($word, self::WEAK, true);
        foreach ($list as $entry) {
            if ($word === $entry
                || (\strlen($word) >= 6 && \strlen($entry) >= 6 && 1 === levenshtein($word, $entry))
                || (!$weak && \strlen($word) >= 5 && !\in_array($entry, self::WEAK, true) && str_contains($entry, $word))) {
                return true;
            }
        }

        return false;
    }

    /**
     * `found()` in both directions: Google's word inside one of ours, or one of ours inside Google's word.
     *
     * @param list<string> $ours
     */
    private static function foundEitherWay(string $theirs, array $ours): bool
    {
        if (self::found($theirs, $ours)) {
            return true;
        }
        foreach ($ours as $word) {
            if (self::found($word, [$theirs])) {
                return true;
            }
        }

        return false;
    }
}
