<?php

declare(strict_types=1);

namespace App\Geo\Console;

use App\Geo\Command\ImportTowns;
use App\Geo\ValueObject\ImportTownsResult;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Run by `make seed` and once after each deploy's migrations. Safe to repeat.
 */
#[AsCommand(name: 'app:geo:import-towns', description: 'Import the official towns dataset (idempotent)')]
final class ImportTownsCommand
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Path to the towns CSV (default: data/geo/towns.csv)')]
        ?string $path = null,
    ): int {
        $path ??= $this->projectDir.'/data/geo/towns.csv';
        if (!is_file($path) || !is_readable($path)) {
            $io->error(\sprintf('The towns CSV "%s" is not a readable file.', $path));

            return Command::FAILURE;
        }

        $result = $this->commandBus->dispatch(new ImportTowns($path))->last(HandledStamp::class)?->getResult();
        \assert($result instanceof ImportTownsResult);

        $io->writeln(\sprintf(
            'Towns: %d inserted, %d updated, %d unchanged. ZIPs: %d total, %d added, %d removed.',
            $result->inserted,
            $result->updated,
            $result->unchanged,
            $result->zips,
            $result->zipsAdded,
            $result->zipsRemoved,
        ));

        if ([] !== $result->staleTowns) {
            $io->writeln(\sprintf(
                '%d stale towns kept: %s%s',
                \count($result->staleTowns),
                implode(', ', \array_slice($result->staleTowns, 0, 10)),
                \count($result->staleTowns) > 10 ? ', …' : '',
            ));
        }

        return Command::SUCCESS;
    }
}
