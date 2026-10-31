<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ValueObject\RecordKind;

/**
 * A task as the composer sends it, checked for shape; `title` is canonical.
 */
final readonly class NewTask
{
    public function __construct(
        public RecordKind $kind,
        public string $recordId,
        public string $title,
        public \DateTimeImmutable $dueOn,
        public string $assigneeId,
    ) {
    }
}
