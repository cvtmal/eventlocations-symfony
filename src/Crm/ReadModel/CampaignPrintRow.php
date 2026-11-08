<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * One letter of a print list: who it goes to (the Location, its first contact, its address, else the Eintrag's), the
 * Eintrag, and the link (its id gives the token; the short code is what the letter prints).
 */
final readonly class CampaignPrintRow
{
    public function __construct(
        public string $company,
        public ?string $contact,
        public ?string $address,
        public string $venue,
        public ?string $street,
        public ?string $zip,
        public ?string $town,
        public ?string $cohort,
        public string $linkId,
        public string $shortCode,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
