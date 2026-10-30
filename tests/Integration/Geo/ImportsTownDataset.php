<?php

declare(strict_types=1);

namespace App\Tests\Integration\Geo;

use App\Geo\Command\ImportTowns;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Imports the real towns CSV into app_test once per test class (idempotent), so the Geo
 * read models are tested against every real competitor. Use in a KernelTestCase.
 */
trait ImportsTownDataset
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new ImportTowns(\dirname(__DIR__, 3).'/data/geo/towns.csv'));

        self::ensureKernelShutdown();
    }
}
