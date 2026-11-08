<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * Where a paid term stands (G9), stored in `subscriptions.status` (chk_subscriptions_status). The entitlements never
 * read it, they read the dates: `Expired` only records that the nightly `app:subscriptions:expire` has handled the
 * end of the term, so a second run finds nothing to do. Never shown, so it has no catalogue label.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
}
