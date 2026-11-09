<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use App\Portal\ValueObject\ReportGaps;
use App\Portal\ValueObject\ReportMonth;
use App\Portal\ValueObject\ReportSuggestion;

/**
 * One venue's monthly report mail (G10). `detailed` and `trend` are null on Free. `noData` is true when every basic
 * figure of both months is 0. `asOf` is the last stats ingest. `recipients` are the venue's active managers
 * (`OwnerReportQuery::INQUIRY_ADDRESS_FALLBACK` adds the inquiry address when there is none).
 */
final readonly class OwnerReport
{
    /**
     * @param list<ReportFigure>                                           $figures
     * @param list<ReportFigure>|null                                      $detailed
     * @param list<array{label: string, figures: list<ReportFigure>}>|null $trend
     * @param list<array{email: string, name: ?string}>                    $recipients
     */
    public function __construct(
        public string $venueId,
        public int $venueNumber,
        public string $venueName,
        public ReportMonth $month,
        public array $figures,
        public ?array $detailed,
        public ?array $trend,
        public bool $noData,
        public ReportSuggestion $suggestion,
        public ReportGaps $gaps,
        public LocationPlan $plan,
        public bool $pro,
        public ?\DateTimeImmutable $asOf,
        public array $recipients,
    ) {
    }

    /**
     * Whether G16's «Rückmeldungen» row is in the basic figures, so the mail explains it.
     */
    public function showsFeedback(): bool
    {
        return array_any($this->figures, static fn (ReportFigure $figure): bool => OwnerReportFigures::FEEDBACK === $figure->key);
    }
}
