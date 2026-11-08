<?php

declare(strict_types=1);

namespace App\Venues\Console;

use App\Venues\Command\RefreshVenueSubscription;
use App\Venues\ReadModel\SubscriptionTermQuery;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Run daily by `bin/cron-daily` with `--quiet` (G9, Wave G §4a.5): a lapsed paid term drops its venue to Free (the
 * venue stays online; photos above the Free budget are hidden, never deleted), a term paid in advance lifts it on its
 * first day, and each change purges the venue and the listings. One dispatch per venue, so one failure costs one
 * venue (`<number>: <reason>` prints under `--quiet`, exit 1). A second run finds nothing and writes nothing; a missed
 * night is caught up by the next one, and the entitlements read the dates anyway.
 */
#[AsCommand(name: 'app:subscriptions:expire', description: 'End lapsed paid terms and keep every venue on the tier its terms give today')]
final class ExpireSubscriptionsCommand
{
    public function __construct(
        private readonly SubscriptionTermQuery $terms,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Name the number of venues without writing anything')]
        bool $dryRun = false,
    ): int {
        $numbers = $this->terms->venuesToRefresh($this->clock->now());
        if ($dryRun) {
            $io->writeln(\sprintf('%d venues to refresh.', \count($numbers)));

            return Command::SUCCESS;
        }

        $changed = 0;
        $failed = 0;
        foreach ($numbers as $number) {
            try {
                $changed += true === $this->commandBus->dispatch(new RefreshVenueSubscription($number))->last(HandledStamp::class)?->getResult() ? 1 : 0;
            } catch (\Exception $failure) {
                ++$failed;
                $io->writeln(\sprintf('%d: %s', $number, self::reason($failure)), OutputInterface::VERBOSITY_QUIET);
            }
        }
        $io->writeln(\sprintf('%d venues refreshed, %d failed.', $changed, $failed));

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    private static function reason(\Exception $failure): string
    {
        return $failure instanceof HandlerFailedException
            ? (array_values($failure->getWrappedExceptions())[0] ?? $failure)->getMessage()
            : $failure->getMessage();
    }
}
