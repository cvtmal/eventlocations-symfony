<?php

declare(strict_types=1);

namespace App\Crm\Form;

/**
 * What an edit of a timeline entry can change, checked for shape; `body` is canonical.
 */
final readonly class ActivityEdit
{
    public function __construct(
        public string $body,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
