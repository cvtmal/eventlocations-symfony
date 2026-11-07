<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * A new deal of a company (CRM-7). It always starts at `new` (N5). The id comes from EntityId::generate() before
 * dispatch, so the caller can redirect to the record. The tier is named by its code (`tiers.code`), none while the
 * sale is undecided. The summary names the fields that were set, never their values.
 */
final readonly class CreateDeal implements AuditableCommand
{
    public function __construct(
        public string $id,
        public string $name,
        public string $companyId,
        public ?string $venueId = null,
        public ?string $tierCode = null,
        public ?int $amountRappen = null,
        public ?\DateTimeImmutable $contractStartOn = null,
        public ?\DateTimeImmutable $contractEndOn = null,
        public ?\DateTimeImmutable $expectedCloseOn = null,
        public ?string $ownerId = null,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('deal', $this->id);
    }

    public function auditSummary(): array
    {
        return ['fields' => array_keys(array_filter(
            [
                'name' => $this->name,
                'venue' => $this->venueId,
                'tier' => $this->tierCode,
                'amount' => $this->amountRappen,
                'contract_start' => $this->contractStartOn,
                'contract_end' => $this->contractEndOn,
                'expected_close' => $this->expectedCloseOn,
                'owner' => $this->ownerId,
            ],
            static fn (mixed $value): bool => null !== $value,
        ))];
    }
}
