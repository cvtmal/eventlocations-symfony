<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * What CreateCompanyForVenue did for one venue. `companyId` is null when the venue already had a
 * company by the time its lock was free.
 */
final readonly class BootstrapOutcome
{
    private function __construct(
        public ?string $companyId,
        public bool $contactCreated,
        public bool $emailTaken,
    ) {
    }

    public static function created(string $companyId, bool $contactCreated, bool $emailTaken): self
    {
        return new self($companyId, $contactCreated, $emailTaken);
    }

    public static function skipped(): self
    {
        return new self(null, false, false);
    }

    public function isSkipped(): bool
    {
        return null === $this->companyId;
    }
}
