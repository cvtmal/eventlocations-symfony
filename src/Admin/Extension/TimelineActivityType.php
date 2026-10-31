<?php

declare(strict_types=1);

namespace App\Admin\Extension;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A kind of timeline entry (CRM-4, S13), added by the module that owns it. The shell knows the contract, not
 * the kinds: the timeline's tabs and composer come from the registry, and the browser side looks the icon,
 * tone and renderer up by `code()` in `admin-ui/src/timeline/types.js`.
 */
#[AutoconfigureTag('admin.timeline_activity_type')]
interface TimelineActivityType
{
    /**
     * The row of `crm_activity_types`: lower case, at most 30 characters.
     */
    public function code(): string;

    public function label(): TranslatableInterface;

    /**
     * Higher comes first, in the filter tabs and in the composer.
     */
    public function priority(): int;

    public function requiredRole(): string;

    /**
     * True when staff may write an entry of this kind by hand. False for kinds only the system writes
     * (an inquiry, a deal's stage change): `LogActivity` refuses them.
     */
    public function composable(): bool;
}
