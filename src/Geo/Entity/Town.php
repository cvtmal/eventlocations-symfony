<?php

declare(strict_types=1);

namespace App\Geo\Entity;

use App\Geo\Repository\TownRepository;
use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A town (Ortschaft) of the official dataset, one per (canton, name). Written by
 * app:geo:import-towns through TownRepository; never deleted (venues reference it).
 *
 * The expression indexes idx_towns_search_key (GIN trigram on geo_search_key(name)) and
 * idx_towns_earth (GiST on ll_to_earth(lat, lng)) exist in the database only: DBAL cannot
 * introspect expression indexes, so they are deliberately not mapped here.
 */
#[ORM\Entity(repositoryClass: TownRepository::class)]
#[ORM\Table(name: 'towns')]
#[ORM\Index(name: 'idx_towns_canton_code', columns: ['canton_code'])]
#[ORM\Index(name: 'idx_towns_zip', columns: ['zip'])]
#[ORM\UniqueConstraint(name: 'uk_towns_canton_code_name', columns: ['canton_code', 'name'])]
#[ORM\UniqueConstraint(name: 'uk_towns_canton_code_slug', columns: ['canton_code', 'slug'])]
class Town
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Canton::class)]
    #[ORM\JoinColumn(name: 'canton_code', referencedColumnName: 'code', nullable: false, onDelete: 'RESTRICT')]
    private Canton $canton;

    #[ORM\Column(type: Types::STRING, length: 4)]
    private string $zip;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $slug;

    #[ORM\Column(type: Types::INTEGER)]
    private int $bfsNumber;

    #[ORM\Column(type: Types::FLOAT)]
    private float $lat;

    #[ORM\Column(type: Types::FLOAT)]
    private float $lng;

    #[ORM\Column(type: Types::INTEGER)]
    private int $population;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Canton $canton,
        string $zip,
        string $name,
        string $slug,
        int $bfsNumber,
        float $lat,
        float $lng,
        int $population,
    ) {
        $this->id = EntityId::generate();
        $this->canton = $canton;
        $this->zip = $zip;
        $this->name = $name;
        $this->slug = $slug;
        $this->bfsNumber = $bfsNumber;
        $this->lat = $lat;
        $this->lng = $lng;
        $this->population = $population;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        \assert(null !== $this->id);

        return $this->id;
    }

    public function canton(): Canton
    {
        return $this->canton;
    }

    public function zip(): string
    {
        return $this->zip;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function bfsNumber(): int
    {
        return $this->bfsNumber;
    }

    public function lat(): float
    {
        return $this->lat;
    }

    public function lng(): float
    {
        return $this->lng;
    }

    public function population(): int
    {
        return $this->population;
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
