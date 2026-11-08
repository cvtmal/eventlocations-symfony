<?php

declare(strict_types=1);

namespace App\Venues\Console;

use App\Venues\Command\ExpireLastMinuteOffers;
use App\Venues\Event\LastMinuteOfferExpired;
use App\Venues\Event\VenueEvents;
use App\Venues\ReadModel\LastMinuteOffersQuery;
use App\Venues\ValueObject\TermDay;
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
 * Run daily by `bin/cron-daily` with `--quiet` (G14, Wave G §4a.5): deletes the offers «Kurzfristig frei» whose last
 * day has passed (Zurich), then publishes `LastMinuteOfferExpired` for each, after the commit, so Portal mails the
 * managers. The pages hid these offers at midnight already; this only clears the table. A second run finds nothing
 * and writes nothing; a missed night is caught up by the next one. A failure prints its reason under `--quiet`
 * and exits 1.
 */
#[AsCommand(name: 'app:last-minute:expire', description: 'Delete the offers «Kurzfristig frei» past their last day and tell the managers')]
final class ExpireLastMinuteOffersCommand
{
    public function __construct(
        private readonly LastMinuteOffersQuery $offers,
        private readonly MessageBusInterface $commandBus,
        private readonly VenueEvents $events,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Name the number of expired offers without writing anything')]
        bool $dryRun = false,
    ): int {
        $now = $this->clock->now();
        if ($dryRun) {
            $io->writeln(\sprintf('%d offers to delete.', $this->offers->expiredCount($now)));

            return Command::SUCCESS;
        }

        try {
            $expired = $this->commandBus->dispatch(new ExpireLastMinuteOffers(TermDay::of($now)))->last(HandledStamp::class)?->getResult();
        } catch (\Exception $failure) {
            $reason = $failure instanceof HandlerFailedException ? (array_values($failure->getWrappedExceptions())[0] ?? $failure)->getMessage() : $failure->getMessage();
            $io->writeln('Expiring the offers failed: '.$reason, OutputInterface::VERBOSITY_QUIET);

            return Command::FAILURE;
        }
        \assert(\is_array($expired));

        foreach ($expired as $event) {
            \assert($event instanceof LastMinuteOfferExpired);
            $this->events->lastMinuteOfferExpired($event);
        }
        $io->writeln(\sprintf('%d offers deleted.', \count($expired)));

        return Command::SUCCESS;
    }
}
