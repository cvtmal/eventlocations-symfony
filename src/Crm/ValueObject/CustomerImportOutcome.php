<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * What ImportCustomer did for one customer. `companyId` is null when a company of the same folded name
 * existed by the time the name's lock was free.
 */
final readonly class CustomerImportOutcome
{
    private function __construct(
        public ?string $companyId,
        public int $activities,
    ) {
    }

    public static function created(string $companyId, int $activities): self
    {
        return new self($companyId, $activities);
    }

    public static function skipped(): self
    {
        return new self(null, 0);
    }

    public function isSkipped(): bool
    {
        return null === $this->companyId;
    }
}
