<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * The tiers a staff member can pick for a venue or a deal: name and code, best first.
 */
final class TierOptionsQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, string> name → code, the shape of a ChoiceType's `choices`
     */
    public function choices(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT code, name FROM tiers ORDER BY rank_weight DESC, price_rappen DESC, code');

        $choices = [];
        foreach ($rows as $row) {
            \assert(\is_string($row['code']) && \is_string($row['name']));
            $choices[$row['name']] = $row['code'];
        }

        return $choices;
    }
}
