<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Crm\ValueObject\DealProperty;
use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * One inline edit on a deal record: `value` is what was typed, `expected` the stored form the page loaded, which is
 * the field's version (#27's D11). The summary names the field only, never a value.
 */
final readonly class ChangeDealProperty implements AuditableCommand
{
    public function __construct(
        public string $dealId,
        public DealProperty $property,
        public ?string $value,
        public ?string $expected,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('deal', $this->dealId);
    }

    public function auditSummary(): array
    {
        return ['field' => $this->property->value];
    }
}
