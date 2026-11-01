<?php

declare(strict_types=1);

namespace App\Crm\Console;

use App\Crm\Command\EraseContact;
use App\Crm\ReadModel\CoupleRetentionQuery;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * CRM-11: erases the couples past the cut-off of `app:inquiries:purge`. Run daily by `bin/cron-daily` with `--quiet`: the
 * count is for a person at the console, a failure line `<id>: <class>` prints anyway, and the exit code is 1. The line
 * names the class only, because a driver's message can quote an address.
 *
 * F-m7: `EraseContact` locks the contact and then writes the company, the reverse of a company delete, so the two can
 * deadlock. No lock order is taken for it: each couple is one dispatch, a failed id is reported, and the next night
 * retries it. It takes a staff delete of that couple's company during the nightly run.
 */
#[AsCommand(name: 'app:crm:purge', description: 'Erase the couples past the retention period')]
final class PurgeCouplesCommand
{
    public function __construct(
        private readonly CoupleRetentionQuery $couples,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
        #[Autowire('%inquiries.retention_months%')]
        private readonly int $retentionMonths,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Only report how many couples would be erased')]
        bool $dryRun = false,
    ): int {
        $ids = $this->couples->expired($this->clock->now()->modify(\sprintf('-%d months', $this->retentionMonths)));
        if ($dryRun) {
            $io->writeln(\sprintf('%d couples would be erased.', \count($ids)));

            return Command::SUCCESS;
        }

        $erased = 0;
        $failed = 0;
        foreach ($ids as $id) {
            try {
                $this->commandBus->dispatch(new EraseContact($id));
                ++$erased;
            } catch (\Exception $failure) {
                ++$failed;
                $io->writeln(\sprintf('%s: %s', $id, self::cause($failure)), OutputInterface::VERBOSITY_QUIET);
            }
        }

        $io->writeln(\sprintf('%d couples erased, %d failed.', $erased, $failed));

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return class-string
     */
    private static function cause(\Exception $failure): string
    {
        return $failure instanceof HandlerFailedException
            ? (array_values($failure->getWrappedExceptions())[0] ?? $failure)::class
            : $failure::class;
    }
}
