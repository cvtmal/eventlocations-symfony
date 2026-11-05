<?php

declare(strict_types=1);

namespace App\Crm;

use App\Crm\ReadModel\CustomerMatchCompany;
use App\Crm\ReadModel\CustomerMatchIndex;
use App\Crm\ReadModel\CustomerMatchVenue;
use App\Crm\ValueObject\CustomerClassification;
use App\Crm\ValueObject\CustomerRow;
use App\Crm\ValueObject\CustomerRowClass;
use App\Crm\ValueObject\SheetRegion;
use App\Crm\ValueObject\SheetText;

/**
 * Sorts the rows of the customer sheet into classes (#48 R4–R6): invalid rows first, then the places
 * within the sheet (R4), then each place against the CRM (R5). Only a `New` place carries a record. Pure:
 * the CRM comes as a `CustomerMatchIndex` and every key as `geo_fold_de()` gave it.
 *
 * The runner folds `keyCandidates()` in one `CustomerMatchQuery::fold()` call and passes `similarNames()`
 * to `CustomerMatchQuery::similar()`. A suffixed name ("X (Zürich)") is never folded on its own: its key is
 * the name's key and the suffix's key joined by one space, which is what `geo_fold_de()` gives the whole
 * text, because every step of it works character by character and the ` (`…`)` folds to that space
 * (`CustomerMatchQueryTest` proves it). So rows of one name spelled two ways need no extra candidates.
 */
/**
 * @phpstan-type Place array{rows: non-empty-list<CustomerRow>, name: string, suffix: ?string}
 * @phpstan-type Match CustomerMatchVenue|CustomerMatchCompany
 * @phpstan-type Hits array{compatible: list<Match>, unknown: list<Match>, incompatible: list<Match>}
 */
final class CustomerClassifier
{
    private const string EMAIL_SEPARATORS = '/[\s,;\/&]+/u';

    public function __construct(
        private readonly CustomerRecordMapper $mapper,
    ) {
    }

    /**
     * Every text `classify()` looks up a key for: each name, its part before the first comma, each known
     * region's label and each website host.
     *
     * @param list<CustomerRow> $rows
     *
     * @return list<string>
     */
    public static function keyCandidates(array $rows): array
    {
        $texts = [];
        foreach ($rows as $row) {
            if (null === $row->name || '' === $row->name) {
                continue;
            }
            $texts[$row->name] = true;
            $base = self::base($row->name);
            if (null !== $base) {
                $texts[$base] = true;
            }
            $label = $row->region->isKnown() ? $row->region->label() : null;
            if (null !== $label) {
                $texts[$label] = true;
            }
            $host = SheetText::host($row->website);
            if (null !== $host) {
                $texts[$host] = true;
            }
        }

        return array_map(strval(...), array_keys($texts));
    }

    /**
     * The sheet names `CustomerMatchQuery::similar()` compares, distinct.
     *
     * @param list<CustomerRow> $rows
     *
     * @return list<string>
     */
    public static function similarNames(array $rows): array
    {
        $names = [];
        foreach ($rows as $row) {
            if (null !== $row->name && '' !== $row->name) {
                $names[$row->name] = true;
            }
        }

        return array_map(strval(...), array_keys($names));
    }

    /**
     * @param list<CustomerRow>     $rows
     * @param array<string, string> $keys `CustomerMatchQuery::fold(self::keyCandidates($rows))`
     *
     * @return list<CustomerClassification> by `row()`
     */
    public function classify(array $rows, CustomerMatchIndex $index, array $keys): array
    {
        $out = [];

        // R6
        $groups = [];
        foreach ($rows as $row) {
            if (null === $row->name || '' === $row->name) {
                $out[] = new CustomerClassification([$row->number], CustomerRowClass::Invalid, null, null, 'empty name', null, null);
                continue;
            }
            $groups[self::key($row->name, $keys)][] = $row;
        }

        // R4: the places of each name, named apart when there are several
        $places = [];
        foreach ($groups as $group) {
            $clusters = self::clusters($group);
            if (1 === \count($clusters)) {
                $places[] = ['rows' => $clusters[0], 'name' => (string) $clusters[0][0]->name, 'suffix' => null];
                continue;
            }
            $named = self::nameApart($clusters, $keys);
            if (\is_string($named)) {
                $out[] = self::nameRepeated($group, $named);
                continue;
            }
            array_push($places, ...$named);
        }
        // A suffixed name may still meet another name of the sheet: "X (Zürich)" and "X, Zürich".
        [$places, $repeated] = self::apartByFinalKey($places, $keys);
        array_push($out, ...$repeated);

        // R5
        $matched = [];
        foreach ($places as $place) {
            $classification = $this->match($place, $index, $keys);
            $matched[] = [$place, $classification];
        }
        // The same for a name R5 suffixed because its namesake in the CRM is elsewhere.
        $matched = self::apartByNewKey($matched, $keys, $out);

        foreach ($matched as [$place, $classification]) {
            $out[] = $classification;
            $primary = $place['rows'][0];
            foreach (\array_slice($place['rows'], 1) as $duplicate) {
                $out[] = new CustomerClassification(
                    [$primary->number, $duplicate->number],
                    CustomerRowClass::DuplicateInSheet,
                    $duplicate->name,
                    self::finalName($place),
                    self::sharesHost($duplicate, $place['rows']) ? 'same host' : 'same email',
                    null,
                    null,
                );
            }
        }

        usort($out, static fn (CustomerClassification $a, CustomerClassification $b): int => $a->row() <=> $b->row());

        return $out;
    }

    /**
     * R4: rows of one name are one place when their regions fit and they share an email or a host,
     * joined transitively. Each place keeps its rows in sheet order, and the places are ordered by their
     * first row.
     *
     * @param non-empty-list<CustomerRow> $group
     *
     * @return non-empty-list<non-empty-list<CustomerRow>>
     */
    private static function clusters(array $group): array
    {
        $parent = array_keys($group);
        $find = static function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) {
                $i = $parent[$i] = $parent[$parent[$i]];
            }

            return $i;
        };

        $count = \count($group);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                if (self::samePlace($group[$i], $group[$j])) {
                    $a = $find($i);
                    $b = $find($j);
                    if ($a !== $b) {
                        $parent[max($a, $b)] = min($a, $b);
                    }
                }
            }
        }

        $clusters = [];
        foreach ($group as $i => $row) {
            $clusters[$find($i)][] = $row;
        }

        return array_values($clusters);
    }

    private static function samePlace(CustomerRow $a, CustomerRow $b): bool
    {
        if (!$a->region->compatibleWith($b->region)) {
            return false;
        }
        $hostA = SheetText::host($a->website);
        if (null !== $hostA && $hostA === SheetText::host($b->website)) {
            return true;
        }

        return [] !== array_intersect(self::emails($a), self::emails($b));
    }

    /**
     * R4: each place of one name gets ` (<Region>)`, its most specific region; places whose region is
     * missing or shared get ` (<host>)`. When that still leaves a place without a name of its own, the
     * reason why.
     *
     * @param non-empty-list<non-empty-list<CustomerRow>> $clusters
     * @param array<string, string>                       $keys
     *
     * @return list<Place>|string
     */
    private static function nameApart(array $clusters, array $keys): array|string
    {
        $byRegion = [];
        foreach ($clusters as $i => $cluster) {
            $label = self::region($cluster)->isKnown() ? self::region($cluster)->label() : null;
            if (null !== $label) {
                $byRegion[$i] = [$label, self::suffixedKey((string) $cluster[0]->name, $label, $keys)];
            }
        }
        $regionCounts = array_count_values(array_column($byRegion, 1));
        $collided = \count($regionCounts) < \count($byRegion);

        $suffixes = [];
        foreach ($clusters as $i => $cluster) {
            if (isset($byRegion[$i]) && 1 === $regionCounts[$byRegion[$i][1]]) {
                $suffixes[$i] = $byRegion[$i];
                continue;
            }
            $host = self::host($cluster);
            if (null === $host) {
                return $collided ? 'suffix collision' : 'no region or host';
            }
            $suffixes[$i] = [$host, self::suffixedKey((string) $cluster[0]->name, $host, $keys)];
        }
        if (\count(array_unique(array_column($suffixes, 1))) < \count($suffixes)) {
            return 'suffix collision';
        }

        $places = [];
        foreach ($clusters as $i => $cluster) {
            $places[] = ['rows' => $cluster, 'name' => (string) $cluster[0]->name, 'suffix' => $suffixes[$i][0]];
        }

        return $places;
    }

    /**
     * R4, last step: a final key two places share makes both a repeated name.
     *
     * @param list<Place>           $places
     * @param array<string, string> $keys
     *
     * @return array{list<Place>, list<CustomerClassification>}
     */
    private static function apartByFinalKey(array $places, array $keys): array
    {
        $byKey = [];
        foreach ($places as $place) {
            $byKey[self::placeKey($place, $keys)][] = $place;
        }

        $kept = [];
        $repeated = [];
        foreach ($byKey as $shared) {
            if (1 === \count($shared)) {
                $kept[] = $shared[0];
                continue;
            }
            $repeated[] = self::nameRepeated(array_merge(...array_map(static fn (array $place): array => $place['rows'], $shared)), 'suffix collision');
        }

        return [$kept, $repeated];
    }

    /**
     * The R4 last step again for the names R5 suffixed: two new places of one final key would be one company.
     *
     * @param list<array{Place, CustomerClassification}> $matched
     * @param array<string, string>                      $keys
     * @param list<CustomerClassification>               $out     gets the repeated names
     *
     * @return list<array{Place, CustomerClassification}>
     */
    private static function apartByNewKey(array $matched, array $keys, array &$out): array
    {
        $newByKey = [];
        foreach ($matched as $i => [$place, $classification]) {
            if (CustomerRowClass::New === $classification->class) {
                $newByKey[self::placeKey($place, $keys)][] = $i;
            }
        }
        foreach ($newByKey as $shared) {
            if (\count($shared) < 2) {
                continue;
            }
            $rows = [];
            foreach ($shared as $i) {
                array_push($rows, ...$matched[$i][0]['rows']);
                unset($matched[$i]);
            }
            $out[] = self::nameRepeated($rows, 'suffix collision');
        }

        return array_values($matched);
    }

    /**
     * R5 for one place of the sheet. A place R5 names apart leaves with its suffix.
     *
     * @param Place                 $place
     * @param array<string, string> $keys
     *
     * @param-out Place $place
     */
    private function match(array &$place, CustomerMatchIndex $index, array $keys): CustomerClassification
    {
        $region = self::region($place['rows']);
        $label = $region->isKnown() ? $region->label() : null;

        $hits = self::hits(self::placeKey($place, $keys), $index, $region);
        $otherPlace = null;
        if ([] !== self::fitting($hits)) {
            return $this->resolve($place, self::fitting($hits), 'same name');
        }
        if ([] !== $hits['incompatible'] && null === $place['suffix'] && null !== $label) {
            // Every namesake is elsewhere: a different place, named apart by its region and looked up again.
            $otherPlace = $hits['incompatible'][0]->name;
            $place['suffix'] = $label;
            $hits = self::hits(self::placeKey($place, $keys), $index, $region);
            if ([] !== self::fitting($hits)) {
                return $this->resolve($place, self::fitting($hits), 'same name');
            }
        }

        // The loose keys: the part before the comma, and the name without the suffix.
        $looseKeys = [];
        $base = self::base($place['name']);
        if (null !== $base) {
            $looseKeys[] = self::key($base, $keys);
        }
        if (null !== $place['suffix']) {
            $looseKeys[] = self::key($place['name'], $keys);
        }
        $compatible = [];
        $unknown = [];
        foreach (array_unique(array_diff($looseKeys, [self::placeKey($place, $keys)])) as $looseKey) {
            $loose = self::hits($looseKey, $index, $region);
            array_push($compatible, ...$loose['compatible']);
            array_push($unknown, ...$loose['unknown']);
        }
        if ([] !== $compatible) {
            return $this->resolve($place, $compatible, 'same base name');
        }
        if ([] !== $unknown) {
            return self::check($place, 'name without place', $unknown[0]->name);
        }

        // Secondary signals: never a match, only a row to check. A namesake elsewhere is ignored here too.
        foreach ($place['rows'] as $row) {
            $host = SheetText::host($row->website);
            foreach (null === $host ? [] : $index->byHost($host) as $match) {
                if (false !== self::fits($match, $region)) {
                    return self::check($place, 'same host', $match->name);
                }
            }
        }
        foreach ($place['rows'] as $row) {
            foreach (self::emails($row) as $email) {
                foreach ($index->byEmail($email) as $company) {
                    if (false !== self::fits($company, $region)) {
                        return self::check($place, 'same email', $company->name);
                    }
                }
                if ($index->isContactEmail($email)) {
                    // A contact is a person: no candidate name.
                    return self::check($place, 'same email', null);
                }
            }
        }
        foreach ($index->similarTo($place['name']) as $match) {
            if (false !== self::fits($match, $region)) {
                return self::check($place, 'similar name', $match->name);
            }
        }

        $finalName = self::finalName($place);

        return new CustomerClassification(
            self::numbers($place['rows']),
            CustomerRowClass::New,
            $place['name'],
            $finalName,
            null === $otherPlace ? null : 'other canton',
            $otherPlace,
            $this->mapper->map($finalName, $place['rows'][0], \array_slice($place['rows'], 1)),
        );
    }

    /**
     * R5: the companies the fitting hits stand for, a venue for its company.
     *
     * @param Place                 $place
     * @param non-empty-list<Match> $fitting
     */
    private function resolve(array $place, array $fitting, string $reason): CustomerClassification
    {
        $companies = [];
        $viaVenue = [];
        $venueWithout = null;
        foreach ($fitting as $match) {
            if ($match instanceof CustomerMatchVenue) {
                if (null === $match->companyId) {
                    $venueWithout ??= $match->name;
                    continue;
                }
                $companies[$match->companyId] = (string) $match->companyName;
                $viaVenue[$match->companyId] ??= $match->name;
                continue;
            }
            $companies[$match->id] = $match->name;
        }

        $rows = self::numbers($place['rows']);
        $finalName = self::finalName($place);
        if (1 === \count($companies)) {
            $id = array_key_first($companies);

            return isset($viaVenue[$id])
                ? new CustomerClassification($rows, CustomerRowClass::MatchedVenue, $place['name'], $finalName, $reason, $viaVenue[$id], null)
                : new CustomerClassification($rows, CustomerRowClass::MatchedName, $place['name'], $finalName, $reason, $companies[$id], null);
        }
        if ([] !== $companies) {
            $names = array_values($companies);
            sort($names);

            return new CustomerClassification($rows, CustomerRowClass::Ambiguous, $place['name'], $finalName, 'several companies', $names[0], null);
        }

        return new CustomerClassification($rows, CustomerRowClass::Blocked, $place['name'], $finalName, 'venue without company', $venueWithout, null);
    }

    /**
     * The venues and companies of one key, in R3's three buckets.
     *
     * @return Hits
     */
    private static function hits(string $key, CustomerMatchIndex $index, SheetRegion $region): array
    {
        $hits = ['compatible' => [], 'unknown' => [], 'incompatible' => []];
        foreach ([...$index->venuesByKey($key), ...$index->companiesByKey($key)] as $match) {
            $hits[match (self::fits($match, $region)) {
                true => 'compatible',
                null => 'unknown',
                false => 'incompatible',
            }][] = $match;
        }

        return $hits;
    }

    /**
     * @param Hits $hits
     *
     * @return list<Match>
     */
    private static function fitting(array $hits): array
    {
        return [...$hits['compatible'], ...$hits['unknown']];
    }

    /**
     * R3: a venue by its canton; a company by its Eintrag's canton, unknown without one.
     *
     * @param Match $match
     */
    private static function fits(CustomerMatchVenue|CustomerMatchCompany $match, SheetRegion $region): ?bool
    {
        if ($match instanceof CustomerMatchVenue) {
            return $region->compatibleWithCanton($match->cantonCode);
        }
        if ([] === $match->cantons || !$region->isKnown()) {
            return null;
        }
        foreach ($match->cantons as $canton) {
            if (true === $region->compatibleWithCanton($canton)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Place $place
     */
    private static function check(array $place, string $reason, ?string $candidate): CustomerClassification
    {
        return new CustomerClassification(self::numbers($place['rows']), CustomerRowClass::Check, $place['name'], self::finalName($place), $reason, $candidate, null);
    }

    /**
     * @param non-empty-list<CustomerRow> $rows
     */
    private static function nameRepeated(array $rows, string $reason): CustomerClassification
    {
        usort($rows, static fn (CustomerRow $a, CustomerRow $b): int => $a->number <=> $b->number);

        return new CustomerClassification(self::numbers($rows), CustomerRowClass::NameRepeated, $rows[0]->name, null, $reason, null, null);
    }

    /**
     * The most specific region of a place's rows: a canton beats a wider region, any region an unknown one.
     *
     * @param non-empty-list<CustomerRow> $rows
     */
    private static function region(array $rows): SheetRegion
    {
        $best = $rows[0]->region;
        foreach ($rows as $row) {
            if ($row->region->isMoreSpecificThan($best)) {
                $best = $row->region;
            }
        }

        return $best;
    }

    /**
     * @param non-empty-list<CustomerRow> $rows
     */
    private static function host(array $rows): ?string
    {
        foreach ($rows as $row) {
            $host = SheetText::host($row->website);
            if (null !== $host) {
                return $host;
            }
        }

        return null;
    }

    /**
     * @param list<CustomerRow> $rows
     */
    private static function sharesHost(CustomerRow $row, array $rows): bool
    {
        $host = SheetText::host($row->website);
        if (null === $host) {
            return false;
        }
        foreach ($rows as $other) {
            if ($other !== $row && SheetText::host($other->website) === $host) {
                return true;
            }
        }

        return false;
    }

    /**
     * The addresses of an email cell, lower case: the cell may hold two.
     *
     * @return list<string>
     */
    private static function emails(CustomerRow $row): array
    {
        if (null === $row->email) {
            return [];
        }
        $parts = preg_split(self::EMAIL_SEPARATORS, mb_strtolower($row->email), -1, \PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(false === $parts ? [] : $parts, static fn (string $part): bool => str_contains($part, '@')));
    }

    /** The part before the first comma ("Schloss Liebegg, Gränichen" → "Schloss Liebegg"), when there is one. */
    private static function base(string $name): ?string
    {
        if (!str_contains($name, ',')) {
            return null;
        }
        $base = trim(explode(',', $name, 2)[0]);

        return '' === $base ? null : $base;
    }

    /**
     * @param Place $place
     */
    private static function finalName(array $place): string
    {
        return null === $place['suffix'] ? $place['name'] : \sprintf('%s (%s)', $place['name'], $place['suffix']);
    }

    /**
     * The key of the final name; a suffixed one from the keys of its parts (see the class docblock).
     *
     * @param Place                 $place
     * @param array<string, string> $keys
     */
    private static function placeKey(array $place, array $keys): string
    {
        return null === $place['suffix'] ? self::key($place['name'], $keys) : self::suffixedKey($place['name'], $place['suffix'], $keys);
    }

    /**
     * @param array<string, string> $keys
     */
    private static function suffixedKey(string $name, string $suffix, array $keys): string
    {
        return trim(self::key($name, $keys).' '.self::key($suffix, $keys));
    }

    /**
     * @param array<string, string> $keys
     */
    private static function key(string $text, array $keys): string
    {
        return $keys[$text] ?? throw new \LogicException(\sprintf('No key for a text: fold CustomerClassifier::keyCandidates() first (%d characters).', mb_strlen($text)));
    }

    /**
     * @param list<CustomerRow> $rows
     *
     * @return list<int>
     */
    private static function numbers(array $rows): array
    {
        return array_map(static fn (CustomerRow $row): int => $row->number, $rows);
    }
}
