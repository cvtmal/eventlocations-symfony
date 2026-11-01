<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\InquiryStatus;
use App\Inquiries\ValueObject\MailStatus;
use App\Inquiries\ValueObject\Occasion;

/**
 * One inquiry in the inbox's detail pane. `venueId` is null once the venue is deleted, `contactId` once the couple is
 * erased; the inquiry itself outlives both. `venueNumber` is the inquiry's copy, so it stays after the venue: only
 * `venueId` says whether the edit page still exists.
 */
final readonly class InquiryDetail
{
    public function __construct(
        public string $id,
        public string $coupleName,
        public string $venueName,
        public ?string $venueId,
        public int $venueNumber,
        public Occasion $occasion,
        public int $guestCount,
        public ?\DateTimeImmutable $eventDate,
        public string $email,
        public ?string $phone,
        public string $message,
        public InquiryStatus $status,
        public MailStatus $venueMail,
        public MailStatus $guestMail,
        public ?string $contactId,
        public \DateTimeImmutable $receivedAt,
    ) {
    }
}
