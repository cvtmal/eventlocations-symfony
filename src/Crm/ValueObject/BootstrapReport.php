<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * The counts of one bootstrap run, shared by the console and the button (D19). A failure is
 * `<number>: <reason>`, so nothing here can hold a contact value.
 */
final readonly class BootstrapReport
{
    /**
     * @param list<string> $failures
     */
    public function __construct(
        public int $companies,
        public int $contacts,
        public int $withoutContactPerson,
        public int $emailsTaken,
        public int $sharedEmails,
        public int $skipped,
        public array $failures,
        public int $remaining,
    ) {
    }

    public function failed(): int
    {
        return \count($this->failures);
    }
}
