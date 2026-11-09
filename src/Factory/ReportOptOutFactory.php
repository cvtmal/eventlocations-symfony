<?php

declare(strict_types=1);

namespace App\Factory;

use App\Portal\Entity\ReportOptOut;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A venue whose monthly report (G10) is turned off. Needs imported towns, as VenueFactory does, unless a `venue`
 * attribute is passed.
 *
 * @extends PersistentObjectFactory<ReportOptOut>
 */
final class ReportOptOutFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return ReportOptOut::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'venue' => VenueFactory::new(),
            'optedOutAt' => new \DateTimeImmutable(),
        ];
    }
}
