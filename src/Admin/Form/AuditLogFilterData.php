<?php

declare(strict_types=1);

namespace App\Admin\Form;

use App\Admin\ReadModel\AuditLogFilter;
use App\Admin\ValueObject\AuditActorType;

final class AuditLogFilterData
{
    public ?string $actor = null;
    public ?string $subjectType = null;
    public ?string $subjectId = null;
    public ?\DateTimeImmutable $from = null;
    public ?\DateTimeImmutable $to = null;
    public ?AuditActorType $actorType = null;

    public function toFilter(): AuditLogFilter
    {
        return new AuditLogFilter($this->actor, $this->subjectType, $this->subjectId, $this->from, $this->to, $this->actorType);
    }
}
