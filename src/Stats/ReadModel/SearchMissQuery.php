<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

use App\Stats\ValueObject\FunnelWindow;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The searches without results of the scorecard's window (G12), week by week, newest first; only weeks with a miss.
 * Each query is shown as labelled parts: the filter name from `cms` `stats.funnel.misses.param.<key>`, a term code by
 * its `venues` label (an access code too), a town UUID by the town's name (one more statement, only when a town is named), anything else
 * as stored. A part the catalogue does not know shows its raw key, so an old row never breaks the screen.
 */
final class SearchMissQuery
{
    /** Queries shown per week. */
    public const int TOP = 20;

    /**
     * The term parameters of `Search\ReadModel\SearchCriteria::PARAMS` and their `TermGroup` values (Stats may not
     * use Search; `SearchMissQueryTest` pins the two equal).
     */
    public const array TERM_PARAMS = [
        'event' => 'suitable_for',
        'setting' => 'setting',
        'building' => 'building',
        'infra' => 'infrastructure',
        'furniture' => 'furniture',
        'tech' => 'technology',
        'catering' => 'catering',
        'surroundings' => 'surroundings',
        'amenity' => 'amenity',
    ];

    private const string ZONE = 'Europe/Zurich';

    public function __construct(
        private readonly Connection $connection,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<SearchMissWeek>
     */
    public function weeks(FunnelWindow $window, \DateTimeImmutable $now): array
    {
        $first = $window->firstWeek($now->setTimezone(new \DateTimeZone(self::ZONE)));
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT week_start::text AS week, query, searches, total, queries
                FROM (
                    SELECT week_start, query, searches,
                        sum(searches) OVER (PARTITION BY week_start) AS total,
                        count(*) OVER (PARTITION BY week_start) AS queries,
                        row_number() OVER (PARTITION BY week_start ORDER BY searches DESC, query) AS rank
                    FROM search_misses
                    WHERE week_start >= :first
                ) ranked
                WHERE rank <= :top
                ORDER BY week_start DESC, rank
                SQL,
            ['first' => $first->format('Y-m-d'), 'top' => self::TOP],
        );

        $decoded = [];
        $townIds = [];
        foreach ($rows as $row) {
            \assert(\is_string($row['query']));
            parse_str($row['query'], $params);
            $decoded[] = $params;
            if (\is_string($params['place'] ?? null) && Uuid::isValid($params['place'])) {
                $townIds[] = $params['place'];
            }
        }
        $towns = $this->towns($townIds);

        $weeks = [];
        foreach ($rows as $i => $row) {
            \assert(\is_string($row['week']));
            $week = $row['week'];
            $weeks[$week] ??= ['total' => (int) $row['total'], 'queries' => (int) $row['queries'], 'top' => []];
            $weeks[$week]['top'][] = new SearchMissRow($row['query'], $this->parts($decoded[$i], $towns), (int) $row['searches']);
        }

        $zone = new \DateTimeZone(self::ZONE);

        return array_map(
            static fn (string $week, array $data): SearchMissWeek => new SearchMissWeek(new \DateTimeImmutable($week, $zone), $data['total'], $data['queries'], $data['top']),
            array_keys($weeks),
            $weeks,
        );
    }

    /**
     * @param array<int|string, mixed> $params
     * @param array<string, string>    $towns
     *
     * @return list<string>
     */
    private function parts(array $params, array $towns): array
    {
        $parts = [];
        foreach ($params as $key => $value) {
            $key = (string) $key;
            $values = array_values(array_filter(\is_array($value) ? $value : [$value], \is_string(...)));
            if ([] === $values) {
                continue;
            }
            $group = self::TERM_PARAMS[$key] ?? null;
            $labels = array_map(fn (string $code): string => match (true) {
                null !== $group => $this->translator->trans('term.'.$group.'.'.$code, [], 'venues'),
                'access' === $key => $this->translator->trans('access.'.$code, [], 'venues'),
                'place' === $key => $towns[$code] ?? $code,
                'canton' === $key => strtoupper($code),
                default => $code,
            }, $values);
            $labelKey = 'stats.funnel.misses.param.'.$key;
            $label = $this->translator->trans($labelKey, [], 'cms');
            $parts[] = ($label === $labelKey ? $key : $label).': '.implode(', ', $labels);
        }

        return $parts;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, string> town id → name
     */
    private function towns(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /* @var array<string, string> */
        return $this->connection->fetchAllKeyValue(
            'SELECT id::text, name FROM towns WHERE id::text IN (:ids)',
            ['ids' => array_values(array_unique($ids))],
            ['ids' => ArrayParameterType::STRING],
        );
    }
}
