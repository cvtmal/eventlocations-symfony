<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One run of app:venues:import that was not a dry run (#9): what it read, what it changed
 * and which venues failed. A record of the past, so it has getters only.
 */
#[ORM\Entity]
#[ORM\Table(name: 'content_imports')]
class ContentImport
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\Column(type: Types::STRING, length: 500)]
    private string $sourcePath;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $onlyKey;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $pruned;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $forced;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $overwroteCmsEdits;

    #[ORM\Column(type: Types::INTEGER)]
    private int $venuesSeen;

    #[ORM\Column(type: Types::INTEGER)]
    private int $venuesCreated;

    #[ORM\Column(type: Types::INTEGER)]
    private int $venuesUpdated;

    #[ORM\Column(type: Types::INTEGER)]
    private int $venuesUnchanged;

    #[ORM\Column(type: Types::INTEGER)]
    private int $venuesFailed;

    #[ORM\Column(type: Types::INTEGER)]
    private int $venuesUnpublished;

    #[ORM\Column(type: Types::INTEGER)]
    private int $venuesCmsEdited;

    #[ORM\Column(type: Types::INTEGER)]
    private int $photosAdded;

    #[ORM\Column(type: Types::INTEGER)]
    private int $photosRemoved;

    #[ORM\Column(type: Types::INTEGER)]
    private int $documentsAdded;

    #[ORM\Column(type: Types::INTEGER)]
    private int $documentsRemoved;

    /** @var list<array{key: string, messages: list<string>}> */
    #[ORM\Column(type: Types::JSONB)]
    private array $errors;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $finishedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<array{key: string, messages: list<string>}> $errors
     */
    public function __construct(
        string $sourcePath,
        ?string $onlyKey,
        bool $pruned,
        bool $forced,
        bool $overwroteCmsEdits,
        int $venuesSeen,
        int $venuesCreated,
        int $venuesUpdated,
        int $venuesUnchanged,
        int $venuesFailed,
        int $venuesUnpublished,
        int $venuesCmsEdited,
        int $photosAdded,
        int $photosRemoved,
        int $documentsAdded,
        int $documentsRemoved,
        array $errors,
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $finishedAt,
    ) {
        $this->id = EntityId::generate();
        $this->sourcePath = $sourcePath;
        $this->onlyKey = $onlyKey;
        $this->pruned = $pruned;
        $this->forced = $forced;
        $this->overwroteCmsEdits = $overwroteCmsEdits;
        $this->venuesSeen = $venuesSeen;
        $this->venuesCreated = $venuesCreated;
        $this->venuesUpdated = $venuesUpdated;
        $this->venuesUnchanged = $venuesUnchanged;
        $this->venuesFailed = $venuesFailed;
        $this->venuesUnpublished = $venuesUnpublished;
        $this->venuesCmsEdited = $venuesCmsEdited;
        $this->photosAdded = $photosAdded;
        $this->photosRemoved = $photosRemoved;
        $this->documentsAdded = $documentsAdded;
        $this->documentsRemoved = $documentsRemoved;
        $this->errors = $errors;
        $this->startedAt = $startedAt;
        $this->finishedAt = $finishedAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string
    {
        \assert(null !== $this->id);

        return $this->id;
    }

    public function sourcePath(): string
    {
        return $this->sourcePath;
    }

    public function onlyKey(): ?string
    {
        return $this->onlyKey;
    }

    public function pruned(): bool
    {
        return $this->pruned;
    }

    public function forced(): bool
    {
        return $this->forced;
    }

    public function overwroteCmsEdits(): bool
    {
        return $this->overwroteCmsEdits;
    }

    public function venuesSeen(): int
    {
        return $this->venuesSeen;
    }

    public function venuesCreated(): int
    {
        return $this->venuesCreated;
    }

    public function venuesUpdated(): int
    {
        return $this->venuesUpdated;
    }

    public function venuesUnchanged(): int
    {
        return $this->venuesUnchanged;
    }

    public function venuesFailed(): int
    {
        return $this->venuesFailed;
    }

    public function venuesUnpublished(): int
    {
        return $this->venuesUnpublished;
    }

    /**
     * Venues the run kept because the CMS edited them (skipped folders and prune candidates).
     */
    public function venuesCmsEdited(): int
    {
        return $this->venuesCmsEdited;
    }

    public function photosAdded(): int
    {
        return $this->photosAdded;
    }

    public function photosRemoved(): int
    {
        return $this->photosRemoved;
    }

    public function documentsAdded(): int
    {
        return $this->documentsAdded;
    }

    public function documentsRemoved(): int
    {
        return $this->documentsRemoved;
    }

    /**
     * @return list<array{key: string, messages: list<string>}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function startedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): \DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
