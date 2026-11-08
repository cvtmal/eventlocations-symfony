<?php

declare(strict_types=1);

namespace App\Inquiries\ReadModel;

use App\Inquiries\ValueObject\MailStatus;

/**
 * An inquiry whose venue mail failed or has no outcome. No guest data: the staff alert and the cron mail name the
 * inquiry, its venue and its state only.
 */
final readonly class StuckInquiry
{
    public function __construct(
        public string $id,
        public int $venueNumber,
        public string $venueName,
        public MailStatus $venueMailStatus,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
