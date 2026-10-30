<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\VenueEdit;

/**
 * The data class of one venue editor card (D11): it starts from what was loaded and becomes that card's
 * `Venues` command. The controller builds it twice, once for the form and once to diff the submission
 * against (`ChangedFields`), so `from()` must be deterministic.
 *
 * Invalid UTF-8 and a rule's own message: the `+intl-icu` formatter throws on invalid UTF-8 in any message
 * parameter, and `Regex`, `Email` and a field's `invalid_message` pass the value as `{{ value }}`, so the
 * error page would be a 500. Such a rule takes `normalizer: 'mb_scrub'`, such a field
 * `invalid_message_parameters: ['{{ value }}' => '']`; `CleanText` reports the bad bytes either way.
 */
interface VenueCardData
{
    public static function from(VenueEdit $edit): static;

    /**
     * @param list<string> $changedFields from `ChangedFields::between()`
     */
    public function toCommand(int $venueNumber, VenueEdit $edit, array $changedFields): object;
}
