<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Geo\ValueObject\Canton;
use App\Shared\Translation\EnabledLocale;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The cantons with the most published venues, most first and then by code, for the
 * listing's "Andere suchten auch nach" (LP-5; #17 replaces the source with its landing pages).
 * Built on ListingSql without filters, so "published" means exactly what the listing counts.
 */
final class TopCantonsQuery
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EnabledLocale $enabledLocale,
    ) {
    }

    /**
     * @return list<CantonCount>
     */
    public function top(int $limit = 9): array
    {
        $filter = new ListingSql(SearchCriteria::none(), $this->enabledLocale->default());

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                {$filter->with}
                SELECT v.canton_code, count(*) AS venues
                FROM {$filter->from}
                WHERE {$filter->where} AND v.canton_code IS NOT NULL
                GROUP BY v.canton_code
                ORDER BY venues DESC, v.canton_code
                LIMIT :limit
                SQL,
            $filter->params + ['limit' => $limit],
            $filter->types + ['limit' => ParameterType::INTEGER],
        );

        return array_map(static function (array $row): CantonCount {
            \assert(\is_string($row['canton_code']));
            \assert(\is_int($row['venues']));

            return new CantonCount(Canton::from($row['canton_code']), $row['venues']);
        }, $rows);
    }
}
