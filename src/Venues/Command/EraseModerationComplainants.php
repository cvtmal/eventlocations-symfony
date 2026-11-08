<?php

declare(strict_types=1);

namespace App\Venues\Command;

/**
 * D36: erases the complainant's name, e-mail address and message of every complaint whose decision turns
 * `RETENTION_YEARS` old within a day (`decided_at <= now - 3 years + 1 day`), so a nightly run is never late and
 * /datenschutz's "höchstens drei Jahre" holds. The case, its
 * decision and its photo links stay as evidence. The handler returns how many cases it erased, or would erase with
 * `dryRun`; an erased case is never counted again, so a second run erases nothing.
 *
 * Not audited: a retention sweep has no single subject, and `complainant_erased_at` on each case is the record.
 */
final readonly class EraseModerationComplainants
{
    /** /datenschutz says "höchstens drei Jahre ab unserem Entscheid" (`LegalPagesTest` pins both). */
    public const int RETENTION_YEARS = 3;

    public function __construct(
        public bool $dryRun = false,
    ) {
    }
}
