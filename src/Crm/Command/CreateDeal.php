<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;
use App\Venues\ValueObject\Tier;

/**
 * A new deal of a company (CRM-7). It always starts at `new` (N5). The id comes from EntityId::generate() before
 * dispatch, so the caller can redirect to the record. The summary names the fields that were set, never their values.
 */
final readonly class CreateDeal implements AuditableCommand
{
    /**
     * @param list<string> $contactIds
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $companyId,
        public ?string $venueId = null,
        public ?Tier $tier = null,
        public ?int $amountRappen = null,
        public ?\DateTimeImmutable $contractStartOn = null,
        public ?\DateTimeImmutable $contractEndOn = null,
        public ?\DateTimeImmutable $expectedCloseOn = null,
        public ?string $ownerId = null,
        public array $contactIds = [],
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
                'tier' => $this->tier,
                'amount' => $this->amountRappen,
                'contract_start' => $this->contractStartOn,
                'contract_end' => $this->contractEndOn,
                'expected_close' => $this->expectedCloseOn,
                'owner' => $this->ownerId,
                'contacts' => [] === $this->contactIds ? null : $this->contactIds,
            ],
            static fn (mixed $value): bool => null !== $value,
        ))];
    }
}
