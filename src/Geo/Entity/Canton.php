<?php

declare(strict_types=1);

namespace App\Geo\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A canton row. Code-keyed vocabulary row (S4): the primary key is the lower-case code,
 * a documented exception to EntityId. The 27 rows (26 cantons and Liechtenstein, #46) are seeded by
 * the migrations; the label comes from the `geo` catalogue via App\Geo\ValueObject\Canton.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cantons')]
#[ORM\UniqueConstraint(name: 'uk_cantons_bfs_number', columns: ['bfs_number'])]
#[ORM\UniqueConstraint(name: 'uk_cantons_slug', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'uk_cantons_svg_id', columns: ['svg_id'])]
class Canton
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 2)]
    private string $code;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $bfsNumber;

    #[ORM\Column(type: Types::STRING, length: 40)]
    private string $slug;

    #[ORM\Column(name: 'svg_id', type: Types::STRING, length: 5)]
    private string $svgId;

    #[ORM\Column(type: Types::FLOAT)]
    private float $centroidLat;

    #[ORM\Column(type: Types::FLOAT)]
    private float $centroidLng;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code, int $bfsNumber, string $slug, float $centroidLat, float $centroidLng)
    {
        $this->code = $code;
        $this->bfsNumber = $bfsNumber;
        $this->slug = $slug;
        $this->svgId = 'ch-'.$code;
        $this->centroidLat = $centroidLat;
        $this->centroidLng = $centroidLng;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function code(): string
    {
        return $this->code;
    }

    public function bfsNumber(): int
    {
        return $this->bfsNumber;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function svgId(): string
    {
        return $this->svgId;
    }

    public function centroidLat(): float
    {
        return $this->centroidLat;
    }

    public function centroidLng(): float
    {
        return $this->centroidLng;
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
