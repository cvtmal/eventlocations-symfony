<?php

declare(strict_types=1);

namespace App\Venues\Exception;

use App\Shared\Exception\DomainException;

/**
 * A venue's ZIP + town name (+ canton) matches no town, or several (TownResolver).
 */
final class UnresolvedTown extends DomainException
{
    private function __construct(
        private readonly string $place,
        private readonly int $townCount,
    ) {
        parent::__construct(0 === $townCount
            ? \sprintf('Unknown town "%s".', $place)
            : \sprintf('Ambiguous town "%s" (%d towns).', $place, $townCount));
    }

    public static function unknown(string $place): self
    {
        return new self($place, 0);
    }

    public static function ambiguous(string $place, int $townCount): self
    {
        return new self($place, $townCount);
    }

    public function isAmbiguous(): bool
    {
        return $this->townCount > 1;
    }

    /**
     * The address as given: "<zip> <name>[ <canton>]".
     */
    public function place(): string
    {
        return $this->place;
    }

    public function townCount(): int
    {
        return $this->townCount;
    }
}
