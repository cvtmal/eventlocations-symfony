<?php

declare(strict_types=1);

namespace App\Inquiries\Command;

/**
 * Mails staff the inquiries whose venue mail failed, or is still pending since before `pendingBefore`, created since
 * `createdSince` (G7, D56); each inquiry once. The handler returns a `StuckAlert`.
 */
final readonly class AlertStuckInquiries
{
    public function __construct(
        public \DateTimeImmutable $pendingBefore,
        public \DateTimeImmutable $createdSince,
    ) {
    }
}
