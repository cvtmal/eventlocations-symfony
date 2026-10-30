<?php

declare(strict_types=1);

namespace App\Geo\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One postcode of a town. A collapsed town (e.g. Zürich) has many; every ZIP of the
 * dataset stays searchable. Written by app:geo:import-towns through TownRepository.
 */
#[ORM\Entity]
#[ORM\Table(name: 'town_zips')]
#[ORM\Index(name: 'idx_town_zips_town_id', columns: ['town_id'])]
class TownZip
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 4)]
    private string $zip;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Town::class)]
    #[ORM\JoinColumn(name: 'town_id', nullable: false, onDelete: 'CASCADE')]
    private Town $town;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $zip, Town $town)
    {
        $this->zip = $zip;
        $this->town = $town;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function zip(): string
    {
        return $this->zip;
    }

    public function town(): Town
    {
        return $this->town;
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
