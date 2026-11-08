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
 * An inquiry as a line of the inbox and of the "Neue Anfragen" widget. `qualification` (+ reason) is null until the
 * first delivery has run; `override` is the staff mark (G7).
 */
final readonly class InquiryInboxRow
{
    public function __construct(
        public string $id,
        public string $organizerName,
        public ?string $company,
        public string $venueName,
        public Term $eventType,
        public int $guestCount,
        public ?\DateTimeImmutable $eventDate,
        public InquiryStatus $status,
        public MailStatus $venueMail,
        public \DateTimeImmutable $receivedAt,
        public ?Qualification $qualification,
        public ?QualificationReason $qualificationReason,
        public ?InquiryOverride $override,
    ) {
    }
}
