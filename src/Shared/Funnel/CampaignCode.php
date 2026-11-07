<?php

declare(strict_types=1);

namespace App\Shared\Funnel;

/**
 * What a funnel campaign code looks like (G8): letters, digits, `_` and `-`, at most 40, starting with a letter or a
 * digit. That keeps an e-mail address (`@`, `.`) out of `funnel_events`; `chk_funnel_events_campaign_code` holds the
 * same rule. A leading `-` is free for the scorecard's "Ohne Kampagne" filter value.
 *
 * A code names a campaign the staff defined (a mailing, a letter run). Validate staff input with `isValid()` where a
 * code is entered, so a typo is caught there and not as a dropped code in the log; never take a code from a
 * visitor's request.
 */
final readonly class CampaignCode
{
    public const string PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/D';

    public static function isValid(string $value): bool
    {
        return 1 === preg_match(self::PATTERN, $value);
    }
}
