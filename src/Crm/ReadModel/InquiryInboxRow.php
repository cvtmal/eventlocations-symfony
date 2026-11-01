<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\InquiryStatus;
use App\Inquiries\ValueObject\MailStatus;

/**
 * An inquiry as a line of the inbox and of the "Neue Anfragen" widget.
 */
final readonly class InquiryInboxRow
{
    public function __construct(
        public string $id,
        public string $coupleName,
        public string $venueName,
        public int $guestCount,
        public ?\DateTimeImmutable $eventDate,
        public InquiryStatus $status,
        public MailStatus $venueMail,
        public \DateTimeImmutable $receivedAt,
    ) {
    }
}
