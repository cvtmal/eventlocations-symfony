<?php

declare(strict_types=1);

namespace App\Stats\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One figure of the daily rollup (#47, contracts C4 and C5): a day, a venue number (0 = site-wide) and a
 * `Metric` value, with its events and distinct visitors. Mapping only: written by `StatsRollup` with DBAL,
 * which replaces a day's rows, never updates them. Hence no updated_at.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stats_daily')]
#[ORM\Index(name: 'idx_stats_daily_venue_number_day', columns: ['venue_number', 'day'])]
class DailyStat
{
    #[ORM\Id]
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $day;

    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    private int $venueNumber;

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 30)]
    private string $metric;

    #[ORM\Column(type: Types::INTEGER)]
    private int $events;

    #[ORM\Column(type: Types::INTEGER)]
    private int $visitors;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(\DateTimeImmutable $day, int $venueNumber, string $metric, int $events, int $visitors, \DateTimeImmutable $createdAt)
    {
        $this->day = $day;
        $this->venueNumber = $venueNumber;
        $this->metric = $metric;
        $this->events = $events;
        $this->visitors = $visitors;
        $this->createdAt = $createdAt;
    }

    public function day(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function venueNumber(): int
    {
        return $this->venueNumber;
    }

    public function metric(): string
    {
        return $this->metric;
    }

    public function events(): int
    {
        return $this->events;
    }

    public function visitors(): int
    {
        return $this->visitors;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
