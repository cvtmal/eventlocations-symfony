<?php

declare(strict_types=1);

namespace App\Stats\Entity;

use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A search without results, counted per ISO week (G12). Mapping only: `RecordSearchMissHandler` upserts with DBAL
 * and `SearchMissQuery` reads with DBAL. No person: the row holds the week, the canonical query and a count, no time of a search.
 */
#[ORM\Entity]
#[ORM\Table(name: 'search_misses')]
#[ORM\UniqueConstraint(name: 'uk_search_misses_week_start_query', columns: ['week_start', 'query'])]
class SearchMiss
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $weekStart;

    #[ORM\Column(type: Types::STRING, length: 2000)]
    private string $query;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $searches = 1;

    public function __construct(\DateTimeImmutable $weekStart, string $query)
    {
        $this->id = EntityId::generate();
        $this->weekStart = $weekStart;
        $this->query = $query;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function weekStart(): \DateTimeImmutable
    {
        return $this->weekStart;
    }

    public function query(): string
    {
        return $this->query;
    }

    public function searches(): int
    {
        return $this->searches;
    }
}
