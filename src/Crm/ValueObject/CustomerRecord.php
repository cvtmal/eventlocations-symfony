<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * One customer of the sheet as the CRM stores it (#48 R7, R8): a company, its contact and one logged mail
 * per date, made from a primary row and the rows merged into it. `ImportCustomer` carries it. The notes are
 * kept as fields and values, so the handler writes the labels in the catalogue's words.
 */
final readonly class CustomerRecord
{
    /**
     * @param list<\DateTimeImmutable>                                             $mailDates  distinct, ascending, Europe/Zurich
     * @param list<array{row: int, lines: list<array{CustomerNoteField, string}>}> $noteBlocks one per row, the primary first
     * @param list<int>                                                            $rows       the primary first
     * @param list<array{row: int, field: string, reason: string}>                 $warnings   `field` is a `CustomerNoteField` value; no cell value
     */
    public function __construct(
        public string $name,
        public ?string $website,
        public ?string $email,
        public ?string $phone,
        public ?string $firstName,
        public string $lastName,
        public array $mailDates,
        public array $noteBlocks,
        public array $rows,
        public array $warnings,
    ) {
    }
}
