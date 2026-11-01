<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * The contract ends a renewal looks at (CRM-8, D22): from today, Zurich's, through 60 days on, both days included, as
 * `Y-m-d`. Also the due date of a renewal task (D24).
 */
final readonly class RenewalWindow
{
    private const int DAYS = 60;
    private const int TASK_LEAD_DAYS = 30;
    private const string FORMAT = 'Y-m-d';

    private function __construct(
        public string $first,
        public string $last,
    ) {
    }

    public static function at(\DateTimeImmutable $now): self
    {
        $today = TaskDueDate::today($now);

        return new self($today, new \DateTimeImmutable($today)->modify('+'.self::DAYS.' days')->format(self::FORMAT));
    }

    /**
     * 30 days before the contract ends, a default, or today when that has passed.
     */
    public static function taskDueOn(\DateTimeImmutable $end, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $lead = $end->modify(\sprintf('-%d days', self::TASK_LEAD_DAYS));

        return new \DateTimeImmutable(max($lead->format(self::FORMAT), TaskDueDate::today($now)));
    }

    public function contains(\DateTimeImmutable $day): bool
    {
        $date = $day->format(self::FORMAT);

        return $date >= $this->first && $date <= $this->last;
    }
}
