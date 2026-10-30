<?php

declare(strict_types=1);

namespace App\Admin\Form;

use App\Admin\ReadModel\AuditLogFilter;

final class AuditLogFilterData
{
    public ?string $actor = null;
    public ?string $subjectType = null;
    public ?string $subjectId = null;
    public ?\DateTimeImmutable $from = null;
    public ?\DateTimeImmutable $to = null;

    public function toFilter(): AuditLogFilter
    {
        return new AuditLogFilter($this->actor, $this->subjectType, $this->subjectId, $this->from, $this->to);
    }
}
