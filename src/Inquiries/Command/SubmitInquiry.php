<?php

declare(strict_types=1);

namespace App\Inquiries\Command;

/**
 * Stores an inquiry, or finds the row it repeats; the handler returns a `SubmittedInquiry`.
 * `eventDate` is `Y-m-d` or null, `startTime` `H:i` or null; `eventType` is a `suitable_for` code and
 * `cateringWish` a catering code (F3), both checked by the handler.
 */
final readonly class SubmitInquiry
{
    public function __construct(
        public string $venueId,
        public string $venueSlug,
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
}
