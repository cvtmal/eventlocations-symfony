<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Geo\ValueObject\Canton;
use App\Venues\ValueObject\Access;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\TermGroup;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\Uid\Uuid;

/**
 * The listing's filter state and its URL codec (S4). This class owns the query parameter
 * names; filter UIs only render it (hotspot owned by #6).
 *
 * Grammar (anything else is dropped silently, never a 4xx/5xx):
 *
 * - `canton`: one canton code (`zh`, case-insensitive).
 * - `place` + `radius`: a town UUID or a 4-digit ZIP; radius 0–50 km, default 5, kept only
 *   with a place (0 = the town or ZIP itself).
 * - `guests`: 1–50000.
 * - `q`: name search, trimmed, whitespace collapsed, at most 100 characters, needs a letter
 *   or digit.
 * - `setting[]`, `building[]` (OR inside the group), `infra[]`, `furniture[]`, `tech[]`,
 *   `surroundings[]`, `ceremony[]`, `amenity[]`, `usage[]` (AND inside the group): known codes
 *   of the matching TermGroup; `amenity[]` and `usage[]` only those of FILTER_CODES.
 * - `access[]`: Access values (AND).
 * - `price[]`: PriceLevel values (OR, a venue has one).
 * - `curfew`: exactly `none`, the venue stated it has no curfew.
 * - `rooms` 1–6, `area` one of AREAS, `height` one of HEIGHTS (all "at least").
 * - `page`: 1–1000, default 1.
 *
 * toQuery() is canonical: fixed key order, sorted and deduplicated lists, defaults omitted
 * (page 1, radius 5), so one filter state has one URL. Build URLs with
 * http_build_query($criteria->toQuery()).
 */
final readonly class SearchCriteria
{
    /** Query parameter → term group, in toQuery() order. */
    public const PARAMS = [
        'setting' => TermGroup::Setting,
        'building' => TermGroup::Building,
        'infra' => TermGroup::Infrastructure,
        'furniture' => TermGroup::Furniture,
        'tech' => TermGroup::Technology,
        'surroundings' => TermGroup::Surroundings,
        'ceremony' => TermGroup::Ceremony,
        'amenity' => TermGroup::Amenity,
        'usage' => TermGroup::Usage,
    ];
    /**
     * TermGroup value → the only codes that filter (FI-7). A group that is not listed filters on
     * all its codes. Here and not in a template, because a code the URL accepts but no control
     * shows would narrow the results invisibly.
     */
    public const FILTER_CODES = [
        'amenity' => ['overnight', 'dance_floor', 'lake_view', 'panorama', 'outdoor_dining', 'external_caterers', 'wheelchair_access'],
        'usage' => ['exclusive'],
    ];
    public const DEFAULT_RADIUS = 5;
    public const MAX_RADIUS = 50;
    public const AREAS = [50, 100, 150, 200, 250, 300];
    public const HEIGHTS = [2.5, 3.0, 3.5, 4.0, 4.5, 5.0];
    public const MAX_PAGE = 1000;
    public const MAX_GUESTS = 50000;
    public const MAX_ROOMS = 6;
    private const MAX_Q = 100;
    private const MAX_DIGITS = 5;

    /**
     * @param array<string, list<string>> $terms  TermGroup value → sorted, deduplicated codes; only non-empty groups
     * @param list<Access>                $access sorted by value, deduplicated
     * @param list<PriceLevel>            $prices sorted by value, deduplicated
     */
    private function __construct(
        public ?Canton $canton,
        public ?string $place,
        public ?int $radius,
        public ?int $guests,
        public ?string $q,
        public array $terms,
        public array $access,
        public array $prices,
        public bool $noCurfew,
        public ?int $rooms,
        public ?int $area,
        public ?float $height,
        public int $page,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null, null, null, [], [], [], false, null, null, null, 1);
    }

    /**
     * Whether a code of a group can be a filter value: every code, unless FILTER_CODES lists the group.
     */
    public static function offers(TermGroup $group, string $code): bool
    {
        $codes = self::FILTER_CODES[$group->value] ?? null;

        return null === $codes || \in_array($code, $codes, true);
    }

    /**
     * @param InputBag<string> $query
     */
    public static function fromQuery(InputBag $query, KnownTerms $known): self
    {
        // all() without a key never throws; get()/all($key) would on a scalar/array mismatch.
        $raw = $query->all();

        $place = self::place($raw['place'] ?? null);
        $radius = null;
        if (null !== $place) {
            $radius = self::int($raw['radius'] ?? null, 0, self::MAX_RADIUS) ?? self::DEFAULT_RADIUS;
        }

        $terms = [];
        foreach (self::PARAMS as $param => $group) {
            $codes = array_values(array_filter(
                self::strings($raw[$param] ?? null),
                static fn (string $code): bool => $known->has($group, $code) && self::offers($group, $code),
            ));
            if ([] !== $codes) {
                $terms[$group->value] = $codes;
            }
        }

        $access = [];
        foreach (self::strings($raw['access'] ?? null) as $value) {
            $case = Access::tryFrom($value);
            if (null !== $case) {
                $access[$case->value] = $case;
            }
        }
        ksort($access, \SORT_STRING);

        $prices = [];
        foreach (self::strings($raw['price'] ?? null) as $value) {
            $case = PriceLevel::tryFrom($value);
            if (null !== $case) {
                $prices[$case->value] = $case;
            }
        }
        ksort($prices, \SORT_STRING);

        $area = self::int($raw['area'] ?? null, 1, max(self::AREAS));

        return new self(
            \is_string($raw['canton'] ?? null) ? Canton::tryFrom(strtolower($raw['canton'])) : null,
            $place,
            $radius,
            self::int($raw['guests'] ?? null, 1, self::MAX_GUESTS),
            self::q($raw['q'] ?? null),
            $terms,
            array_values($access),
            array_values($prices),
            'none' === ($raw['curfew'] ?? null),
            self::int($raw['rooms'] ?? null, 1, self::MAX_ROOMS),
            null !== $area && \in_array($area, self::AREAS, true) ? $area : null,
            self::height($raw['height'] ?? null),
            self::int($raw['page'] ?? null, 1, self::MAX_PAGE) ?? 1,
        );
    }

    public function withPage(int $page): self
    {
        return new self(
            $this->canton, $this->place, $this->radius, $this->guests, $this->q, $this->terms, $this->access,
            $this->prices, $this->noCurfew, $this->rooms, $this->area, $this->height, max(1, min(self::MAX_PAGE, $page)),
        );
    }

    public function withCanton(?Canton $canton): self
    {
        return new self(
            $canton, $this->place, $this->radius, $this->guests, $this->q, $this->terms, $this->access,
            $this->prices, $this->noCurfew, $this->rooms, $this->area, $this->height, $this->page,
        );
    }

    /**
     * @return list<string>
     */
    public function termCodes(TermGroup $group): array
    {
        return $this->terms[$group->value] ?? [];
    }

    public function isZipPlace(): bool
    {
        return null !== $this->place && 1 === preg_match('/^\d{4}$/', $this->place);
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function toQuery(): array
    {
        $query = [];
        if (null !== $this->canton) {
            $query['canton'] = $this->canton->value;
        }
        if (null !== $this->place) {
            $query['place'] = $this->place;
            if (null !== $this->radius && self::DEFAULT_RADIUS !== $this->radius) {
                $query['radius'] = (string) $this->radius;
            }
        }
        if (null !== $this->guests) {
            $query['guests'] = (string) $this->guests;
        }
        if (null !== $this->q) {
            $query['q'] = $this->q;
        }
        foreach (self::PARAMS as $param => $group) {
            if ([] !== $codes = $this->termCodes($group)) {
                $query[$param] = $codes;
            }
            if ('setting' === $param && [] !== $this->access) {
                $query['access'] = array_map(static fn (Access $access): string => $access->value, $this->access);
            }
        }
        if ([] !== $this->prices) {
            $query['price'] = array_map(static fn (PriceLevel $price): string => $price->value, $this->prices);
        }
        if ($this->noCurfew) {
            $query['curfew'] = 'none';
        }
        if (null !== $this->rooms) {
            $query['rooms'] = (string) $this->rooms;
        }
        if (null !== $this->area) {
            $query['area'] = (string) $this->area;
        }
        if (null !== $this->height) {
            $query['height'] = number_format($this->height, 1, '.', '');
        }
        if (1 !== $this->page) {
            $query['page'] = (string) $this->page;
        }

        return $query;
    }

    private static function int(mixed $value, int $min, int $max): ?int
    {
        if (!\is_string($value) || '' === $value || \strlen($value) > self::MAX_DIGITS || !ctype_digit($value)) {
            return null;
        }
        $int = (int) $value;

        return $int >= $min && $int <= $max ? $int : null;
    }

    private static function place(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        if (1 === preg_match('/^\d{4}$/', $value)) {
            return $value;
        }

        return Uuid::isValid($value) ? strtolower($value) : null;
    }

    private static function q(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        // With /u, invalid UTF-8 makes preg_replace return null.
        $q = preg_replace('/\s+/u', ' ', trim($value));
        if (null === $q) {
            return null;
        }
        $q = trim(mb_substr($q, 0, self::MAX_Q));

        return 1 === preg_match('/[\p{L}\p{N}]/u', $q) ? $q : null;
    }

    private static function height(mixed $value): ?float
    {
        if (!\is_string($value) || \strlen($value) > self::MAX_DIGITS || !is_numeric($value)) {
            return null;
        }
        foreach (self::HEIGHTS as $height) {
            if (abs((float) $value - $height) < 1e-9) {
                return $height;
            }
        }

        return null;
    }

    /**
     * A string is a one-element list; non-string list members (setting[][]=x) are skipped.
     *
     * @return list<string> sorted, deduplicated
     */
    private static function strings(mixed $value): array
    {
        $values = \is_string($value) ? [$value] : (\is_array($value) ? array_filter($value, \is_string(...)) : []);
        $values = array_values(array_unique($values));
        sort($values, \SORT_STRING);

        return $values;
    }
}
