<?php

declare(strict_types=1);

namespace App\Factory;

use App\Portal\Entity\OwnerReport;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A sent monthly report (G10): last month (Europe/Zurich) by default. Needs imported towns, as VenueFactory does,
 * unless a `venue` attribute is passed.
 *
 * @extends PersistentObjectFactory<OwnerReport>
 */
final class OwnerReportFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return OwnerReport::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        $zurich = new \DateTimeZone('Europe/Zurich');

        return [
            'venue' => VenueFactory::new(),
            'reportMonth' => new \DateTimeImmutable('first day of last month midnight', $zurich),
            'sentAt' => new \DateTimeImmutable(),
        ];
    }
}
