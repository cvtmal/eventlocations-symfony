<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The title of a venue document in one locale (S4's "document_translations").
 */
#[ORM\Entity]
#[ORM\Table(name: 'venue_document_translations')]
#[ORM\Index(name: 'idx_venue_document_translations_venue_document_id', columns: ['venue_document_id'])]
#[ORM\UniqueConstraint(name: 'uk_venue_document_translations_venue_document_id_locale', columns: ['venue_document_id', 'locale'])]
class VenueDocumentTranslation
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: VenueDocument::class)]
    #[ORM\JoinColumn(name: 'venue_document_id', nullable: false, onDelete: 'CASCADE')]
    private VenueDocument $document;

    #[ORM\Column(type: Types::STRING, length: 2)]
    private string $locale;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $title;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(VenueDocument $document, string $locale, string $title)
    {
        $this->id = EntityId::generate();
        $this->document = $document;
        $this->locale = $locale;
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function retitle(string $title): void
    {
        $this->title = $title;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        \assert(null !== $this->id);

        return $this->id;
    }

    public function document(): VenueDocument
    {
        return $this->document;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function title(): string
    {
        return $this->title;
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
