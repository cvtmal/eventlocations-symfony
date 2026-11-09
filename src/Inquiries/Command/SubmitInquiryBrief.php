<?php

declare(strict_types=1);

namespace App\Inquiries\Command;

/**
 * One brief from the guest to several venues (F13, D17): what the guest typed once, and the venues they ticked,
 * venue id => current slug in the guest's order. Each venue gets a child inquiry built by `forVenue()`.
 */
final readonly class SubmitInquiryBrief
{
    /**
     * @param array<string, string> $venues    venue id => slug
     * @param string|null           $eventDate Y-m-d
     * @param string|null           $startTime H:i
     */
    public function __construct(
        public array $venues,
        public string $locale,
        public string $eventType,
        public ?string $company,
        public int $guests,
        public ?string $eventDate,
        public bool $dateFlexible,
        public ?string $startTime,
        public ?string $cateringWish,
        public ?int $budgetChf,
        public string $message,
        public string $firstName,
        public string $lastName,
        public string $email,
        public ?string $phone,
    ) {
    }

    public function forVenue(string $venueId, string $venueSlug): SubmitInquiry
    {
        return new SubmitInquiry(
            $venueId,
            $venueSlug,
            $this->locale,
            $this->eventType,
            $this->company,
            $this->guests,
            $this->eventDate,
            $this->dateFlexible,
            $this->startTime,
            $this->cateringWish,
            $this->budgetChf,
            $this->message,
            $this->firstName,
            $this->lastName,
            $this->email,
            $this->phone,
        );
    }
}
