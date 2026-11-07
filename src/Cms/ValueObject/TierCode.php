<?php

declare(strict_types=1);

namespace App\Cms\ValueObject;

/**
 * The route requirement of the tier screens (F12): a tier's `code`, the shape `chk_tiers_code` allows. A code the
 * database does not hold matches it too, and is a 404 from the read model. `ChecksAdminRoutes` fills a parameter
 * with this requirement with `free`, the seeded row.
 */
final readonly class TierCode
{
    public const string REQUIREMENT = '[a-z][a-z0-9_]*';
}
