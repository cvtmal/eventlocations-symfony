<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * What one run of `app:crm:import-customers` did or, in a dry run, would do (#48 research §7). `summary()`
 * is the console's sentence, `markdown()` the report file under CONTENT_ROOT/owner/. Both name only row
 * numbers, company and venue names, classes, reasons and field names: never an email, a phone or a person,
 * and no other cell. A failure is `row <n>: <reason>`, the reason a constraint name or a class.
 *
 * A class counts sheet rows: a place under its primary row, each duplicate under its own, every row of a
 * repeated name. So the class counts add up to `rowCount`.
 */
final readonly class CustomerImportReport
{
    /**
     * @param list<CustomerClassification> $classifications by `row()`
     * @param int                          $created         companies created, or on a dry run the ones a real run would create
     * @param int                          $contacts        contacts created, likewise
     * @param int                          $activities      mails logged, likewise
     * @param int                          $skippedAtWrite  new customers the handler skipped: a company of the folded name existed by then (real run)
     * @param list<string>                 $failures        `row <n>: <reason>`
     */
    public function __construct(
        public bool $dryRun,
        public int $rowCount,
        public array $classifications,
        public int $created,
        public int $contacts,
        public int $activities,
        public int $skippedAtWrite,
        public array $failures,
    ) {
    }

    public function summary(): string
    {
        return \sprintf(
            '%sCustomers: %d rows, %d new, %d matched (venue), %d matched (name), %d duplicate in sheet, %d to check, %d name repeated, %d ambiguous, %d blocked, %d invalid; %d companies, %d contacts, %d activities, %d warnings, %d failed.',
            $this->dryRun ? 'Dry run: ' : '',
            $this->rowCount,
            $this->count(CustomerRowClass::New),
            $this->count(CustomerRowClass::MatchedVenue),
            $this->count(CustomerRowClass::MatchedName),
            $this->count(CustomerRowClass::DuplicateInSheet),
            $this->count(CustomerRowClass::Check),
            $this->count(CustomerRowClass::NameRepeated),
            $this->count(CustomerRowClass::Ambiguous),
            $this->count(CustomerRowClass::Blocked),
            $this->count(CustomerRowClass::Invalid),
            $this->created,
            $this->contacts,
            $this->activities,
            \count($this->warnings()),
            \count($this->failures),
        );
    }

    /** The sheet rows of a class. */
    public function count(CustomerRowClass $class): int
    {
        $rows = 0;
        foreach ($this->of($class) as $classification) {
            $rows += CustomerRowClass::NameRepeated === $class ? \count($classification->rows) : 1;
        }

        return $rows;
    }

    /**
     * `row <n>: <class>` for every sheet row that is not written, by row.
     *
     * @return list<string>
     */
    public function skippedRows(): array
    {
        $lines = [];
        foreach ($this->classifications as $classification) {
            if (CustomerRowClass::New === $classification->class) {
                continue;
            }
            $rows = CustomerRowClass::NameRepeated === $classification->class ? $classification->rows : [$classification->row()];
            foreach ($rows as $row) {
                $lines[$row] = \sprintf('row %d: %s', $row, $classification->class->value);
            }
        }
        ksort($lines);

        return array_values($lines);
    }

    /**
     * @return list<array{row: int, field: string, reason: string}>
     */
    public function warnings(): array
    {
        $warnings = [];
        foreach ($this->classifications as $classification) {
            array_push($warnings, ...($classification->record->warnings ?? []));
        }
        usort($warnings, static fn (array $a, array $b): int => [$a['row'], $a['field']] <=> [$b['row'], $b['field']]);

        return $warnings;
    }

    public function markdown(): string
    {
        $out = ['# Customer import'.($this->dryRun ? ' (dry run)' : ''), '', '- '.$this->summary()];
        if (!$this->dryRun) {
            $out[] = \sprintf('- New customers skipped at write, a company of the name existed by then: %d.', $this->skippedAtWrite);
        }
        $out[] = '';

        $out = [...$out, ...self::section('New', $this->lines(CustomerRowClass::New, static function (CustomerClassification $c): string {
            $line = \sprintf('row %d: %s', $c->row(), $c->finalName);
            if ($c->isNamedApart()) {
                $line .= ', named apart from '.$c->sheetName;
            }
            if (null !== $c->candidate) {
                $line .= \sprintf(' (%s: %s)', $c->reason, $c->candidate);
            }

            return $line;
        }))];
        $out = [...$out, ...self::section('Matched (venue)', $this->lines(CustomerRowClass::MatchedVenue, static fn (CustomerClassification $c): string => \sprintf('row %d: %s → %s', $c->row(), $c->sheetName, $c->candidate)))];
        $out = [...$out, ...self::section('Matched (name)', $this->lines(CustomerRowClass::MatchedName, static fn (CustomerClassification $c): string => \sprintf('row %d: %s → %s', $c->row(), $c->sheetName, $c->candidate)))];
        $out = [...$out, ...self::section('Duplicate in sheet', $this->lines(CustomerRowClass::DuplicateInSheet, static fn (CustomerClassification $c): string => \sprintf('row %d: %s → row %d (%s)', $c->row(), $c->sheetName, $c->rows[0], $c->reason)))];
        $out = [...$out, ...self::section('To check', $this->lines(CustomerRowClass::Check, static fn (CustomerClassification $c): string => \sprintf('row %d: %s: %s', $c->row(), $c->sheetName, $c->reason).(null === $c->candidate ? '' : ', candidate '.$c->candidate)), 'Skipped. Staff add the real ones by hand.')];
        $out = [...$out, ...self::section('Name repeated', $this->lines(CustomerRowClass::NameRepeated, static fn (CustomerClassification $c): string => \sprintf('rows %s: %s (%s)', implode(', ', $c->rows), $c->sheetName, $c->reason)), 'The sheet cannot tell these places apart: add ", Ort" in the sheet.')];
        $out = [...$out, ...self::section('Ambiguous', $this->lines(CustomerRowClass::Ambiguous, static fn (CustomerClassification $c): string => \sprintf('row %d: %s: %s', $c->row(), $c->sheetName, $c->reason).(null === $c->candidate ? '' : ', one is '.$c->candidate)))];
        $out = [...$out, ...self::section('Blocked', $this->lines(CustomerRowClass::Blocked, static fn (CustomerClassification $c): string => \sprintf('row %d: %s → %s', $c->row(), $c->sheetName, $c->candidate)), 'These rows match a venue without a company: run `bin/prod-import crm-bootstrap`, then this import again.')];
        $out = [...$out, ...self::section('Invalid', $this->lines(CustomerRowClass::Invalid, static fn (CustomerClassification $c): string => \sprintf('row %d: %s', $c->row(), $c->reason)))];
        $out = [...$out, ...self::section('Warnings', array_map(static fn (array $w): string => \sprintf('row %d: %s: %s', $w['row'], $w['field'], $w['reason']), $this->warnings()), 'The field was left empty and its cell went into the company note.')];
        $out = [...$out, ...self::section('Failed', $this->failures)];

        return implode("\n", $out);
    }

    /**
     * @return list<CustomerClassification>
     */
    private function of(CustomerRowClass $class): array
    {
        return array_values(array_filter($this->classifications, static fn (CustomerClassification $c): bool => $class === $c->class));
    }

    /**
     * @param \Closure(CustomerClassification): string $line
     *
     * @return list<string>
     */
    private function lines(CustomerRowClass $class, \Closure $line): array
    {
        return array_map($line, $this->of($class));
    }

    /**
     * @param list<string> $items
     *
     * @return list<string>
     */
    private static function section(string $title, array $items, ?string $intro = null): array
    {
        $out = ['## '.$title, ''];
        if (null !== $intro) {
            $out = [...$out, $intro, ''];
        }
        if ([] === $items) {
            return [...$out, 'None.', ''];
        }
        foreach ($items as $item) {
            $out[] = '- '.self::inline($item);
        }

        return [...$out, ''];
    }

    /** One line of Markdown: a line break in a name would start a new item. */
    private static function inline(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
