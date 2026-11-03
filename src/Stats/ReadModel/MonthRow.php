<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

/**
 * The site's figures of one calendar month (#47, media kit); the current month runs to today.
 */
final readonly class MonthRow
{
    /**
     * @param \DateTimeImmutable $month midnight of the month's first day, Zurich
     */
    public function __construct(
        public \DateTimeImmutable $month,
        public int $visits,
        public int $pageViews,
        public int $detailViews,
        public int $contactClicks,
        public int $merklisteAdds,
        public int $inquiries,
    ) {
    }
}
