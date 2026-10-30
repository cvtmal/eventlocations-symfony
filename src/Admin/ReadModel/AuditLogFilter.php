<?php

declare(strict_types=1);

namespace App\Admin\ReadModel;

/**
 * What the audit screen narrows by. `from` and `to` are calendar days, both inclusive, in Europe/Zurich.
 */
final readonly class AuditLogFilter
{
    public function __construct(
        public ?string $actorId = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
    ) {
    }
}
