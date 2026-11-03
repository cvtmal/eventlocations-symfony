<?php

declare(strict_types=1);

namespace App\Stats\Entity;

use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One spool file the ingest has loaded (#47, contract C3); its name is unique, so a rerun skips it.
 * Mapping only: written by `IngestStatsHandler` with DBAL. Append-only, so no updated_at.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stats_ingest_files')]
#[ORM\UniqueConstraint(name: 'uk_stats_ingest_files_file_name', columns: ['file_name'])]
class IngestedSpoolFile
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $fileName;

    #[ORM\Column(type: Types::INTEGER)]
    private int $events;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $fileName, int $events, \DateTimeImmutable $createdAt)
    {
        $this->id = EntityId::generate();
        $this->fileName = $fileName;
        $this->events = $events;
        $this->createdAt = $createdAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function fileName(): string
    {
        return $this->fileName;
    }

    public function events(): int
    {
        return $this->events;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
