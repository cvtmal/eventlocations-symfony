<?php

declare(strict_types=1);

namespace App\Venues\ValueObject\Content;

/**
 * What app:legacy:export-content did or, in a dry run, would do: counts and `<key>: <reason>`
 * lines. It never holds a contact value.
 */
final readonly class LegacyExportReport
{
    /**
     * @param array<string, int> $counts     counters by name, see the keys in lines()
     * @param array<string, int> $regions    the region texts without a canton, most first
     * @param list<string>       $cantons    the canton codes in use
     * @param list<string>       $notes      `<key>: <reason>`, also stale folders and skipped rows
     * @param list<string>       $unresolved `row <n>: <reason>`
     */
    public function __construct(
        public bool $dryRun,
        public array $counts,
        public array $regions,
        public array $cantons,
        public array $notes,
        public array $unresolved,
    ) {
    }

    /**
     * @return list<string> the summary, one line per subject
     */
    public function lines(): array
    {
        $c = $this->counts;
        $regions = implode(', ', array_map(static fn (string $region, int $count): string => $region.' '.$count, array_keys($this->regions), $this->regions));

        return [
            \sprintf('%sRows: %d. Mapped: %d (%d created, %d updated, %d unchanged). Skipped: %d. Unresolved: %d.', $this->dryRun ? 'Dry run: ' : '', $c['rows'], $c['created'] + $c['updated'] + $c['unchanged'], $c['created'], $c['updated'], $c['unchanged'], $c['skipped'], \count($this->unresolved)),
            \sprintf('Place: %d canton, %d region text%s. Cantons used: %d.', $c['canton'], $c['region'], '' === $regions ? '' : ' ('.$regions.')', \count($this->cantons)),
            \sprintf('Contact: %d phone dropped, %d phones empty, %d e-mails dropped, %d websites dropped.', $c['phoneDropped'], $c['phoneEmpty'], $c['emailDropped'], $c['websiteDropped']),
            \sprintf('Descriptions: %d section, %d before facts, %d long paragraphs, %d none.', $c['rule1'], $c['rule2'], $c['rule3'], $c['rule4']),
            \sprintf('Summaries: %d sentence, %d word cut, %d whole, %d Ambiente.', $c['sentence'], $c['word'], $c['whole'], $c['ambience']),
        ];
    }
}
