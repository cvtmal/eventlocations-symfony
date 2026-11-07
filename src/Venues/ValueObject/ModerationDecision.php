<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * What staff decide on a complaint (G2): `remove` revokes the named photos (G1's `RemoveVenuePhoto`) and leaves an
 * entry the case took offline offline; `keep` lifts the hold and puts such an entry back online. Stored in
 * `moderation_cases.decision` (`chk_moderation_cases_decision`). Staff read `moderation.decision.<value>` in `cms`.
 */
enum ModerationDecision: string
{
    case Remove = 'remove';
    case Keep = 'keep';
}
