<?php

declare(strict_types=1);

namespace App\Inquiries\ReadModel;

/**
 * An inquiry whose venue mail has no outcome. Identifiers only: the report goes out by cron mail.
 */
final readonly class StuckInquiry
{
    public function __construct(
        public string $id,
        public int $venueNumber,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
