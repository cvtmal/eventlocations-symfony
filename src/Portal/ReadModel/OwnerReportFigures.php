<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use App\Portal\ValueObject\ReportMonth;
use App\Stats\ReadModel\VenueMonth;
use App\Stats\ValueObject\ClickKind;

/**
 * The rows of the monthly report (G10), built from Stats' `VenueMonth`. A figure is one `ReportFigure`, so a later
 * one costs one row here and one catalogue line.
 */
final readonly class OwnerReportFigures
{
    /**
     * Every venue: «Besuche», «Interaktionen», «Zugestellte Anfragen», against the month before. The qualified
     * inquiries (G7) get a row only once Stats supplies a number (D52).
     *
     * @return list<ReportFigure>
     */
    public static function basic(VenueMonth $now, VenueMonth $before): array
    {
        $figures = [
            new ReportFigure('visits', $now->summary->detailVisitors, $before->summary->detailVisitors),
            new ReportFigure('interactions', self::interactions($now), self::interactions($before)),
            new ReportFigure('delivered', $now->deliveredInquiries, $before->deliveredInquiries),
        ];
        if (null !== $now->qualifiedInquiries) {
            $figures[] = new ReportFigure('qualified', $now->qualifiedInquiries, $before->qualifiedInquiries);
        }

        return $figures;
    }

    /**
     * Pro (`Entitlements::$detailedReport`): clicks per kind, card impressions, Merkliste adds, submitted inquiries.
     *
     * @return list<ReportFigure>
     */
    public static function detailed(VenueMonth $now, VenueMonth $before): array
    {
        $figures = [];
        foreach (ClickKind::cases() as $kind) {
            $figures[] = new ReportFigure('clicks_'.$kind->value, $now->summary->clicks[$kind->value] ?? 0, $before->summary->clicks[$kind->value] ?? 0);
        }
        $figures[] = new ReportFigure('impressions', $now->summary->impressions, $before->summary->impressions);
        $figures[] = new ReportFigure('merkliste_adds', $now->summary->merklisteAdds, $before->summary->merklisteAdds);
        $figures[] = new ReportFigure('submitted', $now->summary->inquiries, $before->summary->inquiries);

        return $figures;
    }

    /**
     * Pro: the basic figures of each month, newest first, without a comparison.
     *
     * @param list<VenueMonth> $months newest first
     *
     * @return list<array{label: string, figures: list<ReportFigure>}>
     */
    public static function trend(array $months): array
    {
        return array_map(static fn (VenueMonth $month): array => [
            'label' => ReportMonth::fromString($month->month->format('Y-m'))?->label() ?? $month->month->format('Y-m'),
            'figures' => [
                new ReportFigure('visits', $month->summary->detailVisitors),
                new ReportFigure('interactions', self::interactions($month)),
                new ReportFigure('delivered', $month->deliveredInquiries),
            ],
        ], $months);
    }

    /**
     * True when every basic figure of both months is 0: the mail says so plainly.
     *
     * @param list<ReportFigure> $basic
     */
    public static function isEmpty(array $basic): bool
    {
        foreach ($basic as $figure) {
            if (0 !== $figure->current || 0 !== ($figure->previous ?? 0)) {
                return false;
            }
        }

        return true;
    }

    private static function interactions(VenueMonth $month): int
    {
        return $month->summary->clickTotal + $month->summary->merklisteAdds;
    }
}
