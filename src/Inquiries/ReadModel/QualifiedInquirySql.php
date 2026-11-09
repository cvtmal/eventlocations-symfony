<?php

declare(strict_types=1);

namespace App\Inquiries\ReadModel;

/**
 * The one definition of a counted qualified inquiry (G7, D19, D56): qualified at its latest delivery and not marked
 * spam or test by staff. Stats reads it for the funnel scorecard; the owner report (G10) reads it too (`Stats\ReadModel\VenueMonthQuery`), so the
 * figures can never disagree.
 */
final class QualifiedInquirySql
{
    private function __construct()
    {
    }

    /**
     * @return string a condition over `inquiries <alias>`
     */
    public static function where(string $alias): string
    {
        // The D modifier: without it `$` also matches before a final newline.
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/D', $alias)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a plain SQL alias of the inquiries table.', $alias));
        }

        return "{$alias}.qualification = 'qualified' AND {$alias}.qualification_override IS NULL";
    }
}
