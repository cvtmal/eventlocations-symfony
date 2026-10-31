<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ValueObject\RecordKind;

/**
 * A timeline entry as the composer sends it, checked for shape; `body` is canonical.
 */
final readonly class NewActivity
{
    public function __construct(
        public RecordKind $kind,
        public string $recordId,
        public string $type,
        public string $body,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
