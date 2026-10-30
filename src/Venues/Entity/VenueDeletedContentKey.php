<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The content key of a venue deleted in the CMS (#24), so app:venues:import does not bring
 * it back from its folder. created_at is when the venue was deleted. An import told to
 * overwrite CMS edits recreates the venue and deletes this row.
 */
#[ORM\Entity]
#[ORM\Table(name: 'venue_deleted_content_keys')]
#[ORM\UniqueConstraint(name: 'uk_venue_deleted_content_keys_content_key', columns: ['content_key'])]
class VenueDeletedContentKey
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $contentKey;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $contentKey)
    {
        $this->id = EntityId::generate();
        $this->contentKey = $contentKey;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string
    {
        \assert(null !== $this->id);

        return $this->id;
    }

    public function contentKey(): string
    {
        return $this->contentKey;
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
