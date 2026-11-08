<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Shared\Translation\EnabledLocale;
use App\Venues\ValueObject\Access;
use App\Venues\ValueObject\TermGroup;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether a filter has anything to filter on: at least one published venue, by ListingSql's
 * definition, that carries the data. A control shows when it has data or is active (the caller
 * adds what is active, or a hidden active value would be dropped on the next submit), and it
 * comes back by itself once a venue has the data. Not faceted: other active filters hide nothing.
 *
 * One flags statement and one terms statement per request, however many filters are asked;
 * tagged kernel.reset by autoconfiguration, so a long-running worker asks again.
 */
final class FilterDataQuery implements ResetInterface
{
    /** The `access.*` keys are built from Access's cases, because each case needs a bound value. */
    private const FILTERS = [
        // The radius search measures from the venue's own coordinates, so a canton-only venue with a pin is found.
        'place' => 'v.lat IS NOT NULL AND v.lng IS NOT NULL',
        // Radius 0 matches the venue's town or ZIP; without a venue that has a town it would match nothing.
        'town' => 'v.town_id IS NOT NULL',
        'rooms' => 'v.room_count >= 1',
        'area' => 'v.largest_room_m2 IS NOT NULL',
        'height' => 'v.max_room_height_m IS NOT NULL',
    ];

    /** @var array<string, bool>|null */
    private ?array $answers = null;

    /** @var array<string, array<string, bool>>|null TermGroup value → code → has data, in vocabulary order */
    private ?array $terms = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly EnabledLocale $enabledLocale,
    ) {
    }

    /**
     * @throws \InvalidArgumentException for a filter that does not exist: a template's typo
     */
    public function has(string $filter): bool
    {
        $this->answers ??= $this->loadAnswers();

        return $this->answers[$filter] ?? throw new \InvalidArgumentException(\sprintf('Unknown filter "%s".', $filter));
    }

    /**
     * The group's codes that have data or are active, in vocabulary order: primary_position
     * first, then position. A group that is not a listing filter has none.
     *
     * @param list<string> $active
     *
     * @return list<string>
     */
    public function terms(TermGroup $group, array $active = []): array
    {
        $this->terms ??= $this->loadTerms();

        $codes = [];
        foreach ($this->terms[$group->value] ?? [] as $code => $hasData) {
            if ($hasData || \in_array($code, $active, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public function flagsStatement(): SqlStatement
    {
        $published = new ListingSql(SearchCriteria::none(), $this->enabledLocale->default());
        $params = $published->params;
        foreach (Access::cases() as $access) {
            $params['km_'.$access->value] = $access->maxDistanceKm();
        }

        $columns = array_map(
            static fn (string $predicate): string => "EXISTS (SELECT 1 FROM {$published->from} WHERE {$published->where} AND {$predicate})",
            $this->predicates(),
        );

        return new SqlStatement('SELECT '.implode(",\n", $columns), $params, $published->types);
    }

    public function termsStatement(): SqlStatement
    {
        $published = new ListingSql(SearchCriteria::none(), $this->enabledLocale->default());

        return new SqlStatement(
            <<<SQL
                SELECT t.term_group, t.code, EXISTS (
                    SELECT 1 FROM venue_terms x JOIN {$published->from} ON v.id = x.venue_id
                    WHERE x.term_group = t.term_group AND x.term_code = t.code AND {$published->where}
                ) AS has_data
                FROM taxonomy_terms t
                WHERE t.term_group IN (:groups)
                ORDER BY t.term_group, t.primary_position NULLS LAST, t.position
                SQL,
            $published->params + ['groups' => array_map(static fn (TermGroup $group): string => $group->value, array_values(SearchCriteria::PARAMS))],
            $published->types + ['groups' => ArrayParameterType::STRING],
        );
    }

    public function reset(): void
    {
        $this->answers = null;
        $this->terms = null;
    }

    /**
     * @return array<string, string> filter key → predicate on `v`
     */
    private function predicates(): array
    {
        $predicates = [];
        foreach (Access::cases() as $access) {
            $predicates['access.'.$access->value] = \sprintf('v.%s <= :km_%s', ListingSql::accessColumn($access), $access->value);
        }

        return $predicates + self::FILTERS;
    }

    /**
     * @return array<string, bool>
     */
    private function loadAnswers(): array
    {
        $statement = $this->flagsStatement();
        $row = $this->connection->fetchNumeric($statement->sql, $statement->params, $statement->types);
        \assert(false !== $row);

        return array_map(static fn (mixed $flag): bool => true === $flag, array_combine(array_keys($this->predicates()), $row));
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function loadTerms(): array
    {
        $statement = $this->termsStatement();

        $terms = [];
        foreach ($this->connection->fetchAllAssociative($statement->sql, $statement->params, $statement->types) as $row) {
            \assert(\is_string($row['term_group']) && \is_string($row['code']));
            if (SearchCriteria::offers(TermGroup::from($row['term_group']), $row['code'])) {
                $terms[$row['term_group']][$row['code']] = true === $row['has_data'];
            }
        }

        return $terms;
    }
}
