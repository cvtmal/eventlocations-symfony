<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Shared\Translation\EnabledLocale;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether a filter has anything to filter on: at least one published venue, by ListingSql's
 * definition, that carries the data. A filter without data is hidden and comes back by itself
 * once a venue has it (#21). One `SELECT EXISTS` per filter, memoized per request; tagged
 * kernel.reset by autoconfiguration, so a long-running worker asks again.
 */
final class FilterDataQuery implements ResetInterface
{
    /** #12 adds access, rooms, area and height here. */
    private const FILTERS = ['place' => 'v.town_id IS NOT NULL'];

    /** @var array<string, bool> */
    private array $answers = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly EnabledLocale $enabledLocale,
    ) {
    }

    /**
     * @throws \InvalidArgumentException for a filter that is not in FILTERS: a template's typo
     */
    public function has(string $filter): bool
    {
        $predicate = self::FILTERS[$filter] ?? throw new \InvalidArgumentException(\sprintf('Unknown filter "%s".', $filter));

        return $this->answers[$filter] ??= $this->exists($predicate);
    }

    public function reset(): void
    {
        $this->answers = [];
    }

    private function exists(string $predicate): bool
    {
        $published = new ListingSql(SearchCriteria::none(), $this->enabledLocale->default());

        return (bool) $this->connection->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM {$published->from} WHERE {$published->where} AND {$predicate})",
            $published->params,
            $published->types,
        );
    }
}
