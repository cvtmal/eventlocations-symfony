<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

use App\Venues\ValueObject\TermGroup;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The vocabulary the Merkmale and Hochzeit cards offer: every `taxonomy_terms` row, per group, in the order
 * of the public filter. Loaded once per request (two cards need it); `kernel.reset` by autoconfiguration.
 */
final class TermOptionsQuery implements ResetInterface
{
    /** @var array<string, list<TermOption>>|null */
    private ?array $options = null;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, list<TermOption>> group value → options by `position`, groups in `TermGroup` order
     */
    public function byGroup(): array
    {
        return $this->options ??= $this->load();
    }

    public function reset(): void
    {
        $this->options = null;
    }

    /**
     * @return array<string, list<TermOption>>
     */
    private function load(): array
    {
        $byGroup = array_fill_keys(array_map(static fn (TermGroup $group): string => $group->value, TermGroup::cases()), []);

        $rows = $this->connection->fetchAllAssociative('SELECT term_group, code, primary_position FROM taxonomy_terms ORDER BY term_group, position');
        foreach ($rows as $row) {
            \assert(\is_string($row['term_group']) && \is_string($row['code']));
            \assert(null === $row['primary_position'] || \is_int($row['primary_position']));

            $byGroup[$row['term_group']][] = new TermOption($row['code'], $row['primary_position']);
        }

        return $byGroup;
    }
}
