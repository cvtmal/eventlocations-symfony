<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * One row of the owner's customer sheet (#48), every cell through `SheetText::clean()`, an empty cell as
 * null. `number` is the row as a spreadsheet shows it: the header is row 1, so the first customer is 2.
 */
final readonly class CustomerRow
{
    public function __construct(
        public int $number,
        public ?string $name,
        public ?string $status,
        public ?string $salutation,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $website,
        public ?string $email,
        public ?string $phone,
        public ?string $instagram,
        public ?string $mailSent,
        public ?string $note,
        public SheetRegion $region,
    ) {
    }
}
