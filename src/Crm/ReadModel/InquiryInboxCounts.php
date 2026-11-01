<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\InquiryStatus;

/**
 * How many inquiries each tab of the inbox holds.
 */
final readonly class InquiryInboxCounts
{
    public function __construct(
        public int $new,
        public int $seen,
        public int $done,
        public int $all,
    ) {
    }

    /**
     * The count of one tab; null is "Alle".
     */
    public function of(?InquiryStatus $status): int
    {
        return match ($status) {
            InquiryStatus::New => $this->new,
            InquiryStatus::Seen => $this->seen,
            InquiryStatus::Done => $this->done,
            null => $this->all,
        };
    }
}
