<?php

declare(strict_types=1);

namespace App\Admin\ReadModel;

use App\Admin\ValueObject\AuditActorType;

final readonly class AuditLogRow
{
    /**
     * @param array<string, mixed> $changes the command's summary
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $createdAt,
        public AuditActorType $actorType,
        public ?string $actorName,
        public string $command,
        public string $subjectType,
        public string $subjectId,
        public array $changes,
    ) {
    }

    /**
     * The time of day in Zurich; the date comes from `swiss_date`.
     */
    public function timeInZurich(): string
    {
        return $this->createdAt->setTimezone(new \DateTimeZone('Europe/Zurich'))->format('H:i');
    }

    /**
     * `ChangeAdminRole` for `App\Admin\Command\ChangeAdminRole`.
     */
    public function commandName(): string
    {
        $position = strrpos($this->command, '\\');

        return false === $position ? $this->command : substr($this->command, $position + 1);
    }
}
