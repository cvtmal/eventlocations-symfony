<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

/**
 * One manager change of a published location. `managerName` is null once the account is gone.
 */
final readonly class LocationChangeRow
{
    public function __construct(
        public int $number,
        public string $name,
        public ?string $managerName,
        public string $command,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * `ChangeVenueTexts` for `App\Venues\Command\ChangeVenueTexts`, the key of Cms's `history.command.*`.
     */
    public function commandName(): string
    {
        $position = strrpos($this->command, '\\');

        return false === $position ? $this->command : substr($this->command, $position + 1);
    }
}
