<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\LostReason;
use App\Venues\ValueObject\TierLabel;

/**
 * A deal record page: the deal with its names, its Location's contact rows, and what its forms offer.
 */
final readonly class DealRecord
{
    /**
     * @param string                     $stage         the code of its stage
     * @param list<CompanyRecordContact> $contacts      the contact rows of the deal's Location (#55)
     * @param list<DealFormOption>       $companyVenues the Location's Eintrag (0..1, #56) a deal can name
     * @param list<DealFormOption>       $tierOptions   the tiers the Tier field offers, best first, a tier's code as the id (F12)
     * @param bool                       $renewable     won, with a contract end, and not renewed yet (D21)
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $companyId,
        public string $companyName,
        public ?CompanyRecordVenue $venue,
        public ?TierLabel $tier,
        public ?int $amountRappen,
        public ?string $contractStartOn,
        public ?string $contractEndOn,
        public ?string $expectedCloseOn,
        public string $stage,
        public ?string $ownerId,
        public ?string $ownerName,
        public ?LostReason $lostReason,
        public ?\DateTimeImmutable $closedAt,
        public \DateTimeImmutable $createdAt,
        public array $contacts,
        public array $companyVenues,
        public array $tierOptions,
        public bool $renewable,
    ) {
    }
}
