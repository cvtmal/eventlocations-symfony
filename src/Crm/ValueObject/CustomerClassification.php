<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * The class `CustomerClassifier` gives one place of the sheet (#48 R4–R6), with what the report names: row
 * numbers, the sheet's and the final company name, a short English reason and the name of the venue or
 * company it matched or may match. Never an email, a phone or a person.
 *
 * `rows` holds the primary first:
 * - for a place (`New`, a match, `Check`, `Ambiguous`, `Blocked`), every row of it, its duplicates after it;
 * - for `DuplicateInSheet`, the primary and then the duplicate row this entry is about;
 * - for `NameRepeated`, every row of the name, ascending;
 * - for `Invalid`, the one row.
 */
final readonly class CustomerClassification
{
    /**
     * @param list<int> $rows the primary first
     */
    public function __construct(
        public array $rows,
        public CustomerRowClass $class,
        public ?string $sheetName,
        public ?string $finalName,
        public ?string $reason,
        public ?string $candidate,
        public ?CustomerRecord $record,
    ) {
    }

    /** The row this entry is listed under: the duplicate for `DuplicateInSheet`, the primary otherwise. */
    public function row(): int
    {
        return CustomerRowClass::DuplicateInSheet === $this->class ? $this->rows[\count($this->rows) - 1] : $this->rows[0];
    }

    /** The final name differs from the sheet's: a region or host suffix named the place apart (R4, R5). */
    public function isNamedApart(): bool
    {
        return null !== $this->finalName && $this->finalName !== $this->sheetName;
    }
}
