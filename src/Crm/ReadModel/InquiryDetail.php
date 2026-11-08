<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\InquiryStatus;
use App\Inquiries\ValueObject\InquiryOverride;
use App\Inquiries\ValueObject\MailStatus;
use App\Inquiries\ValueObject\Qualification;
use App\Inquiries\ValueObject\QualificationReason;
use App\Venues\ValueObject\Term;

/**
 * One inquiry in the inbox's detail pane. `venueId` is null once the venue is deleted; the inquiry itself outlives it.
 * `venueNumber` is the inquiry's copy, so it stays after the venue: only `venueId` says whether the edit page still
 * exists. The organizer is no CRM contact (S13): `email` is where a reply goes. `startTime` is `H:i`.
 */
final readonly class InquiryDetail
{
    public function __construct(
        public string $id,
        public string $organizerName,
        public ?string $company,
        public string $venueName,
        public ?string $venueId,
        public int $venueNumber,
        public Term $eventType,
        public int $guestCount,
        public ?\DateTimeImmutable $eventDate,
        public bool $dateFlexible,
        public ?string $startTime,
        public ?Term $cateringWish,
        public ?int $budgetChf,
        public string $email,
        public ?string $phone,
        public string $message,
        public InquiryStatus $status,
        public MailStatus $venueMail,
        public MailStatus $guestMail,
        public \DateTimeImmutable $receivedAt,
        public ?Qualification $qualification,
        public ?QualificationReason $qualificationReason,
        public ?InquiryOverride $override,
    ) {
    }
}
