<?php

declare(strict_types=1);

namespace App\Portal\Console;

use App\Portal\Command\SeedReviewAccounts;
use App\Shared\Security\ReviewMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * review-demo: run by `make review-up` after the demo data. Refuses outside review mode, so the two accounts with the
 * password `admin` can only ever be written to the review stack's database (App\Kernel refuses the flag elsewhere).
 */
#[AsCommand(name: 'app:review:seed-accounts', description: 'Create or reset the review demo\'s admin and manager (review mode only)')]
final class SeedReviewAccountsCommand
{
    public function __construct(
        private readonly ReviewMode $reviewMode,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if (!$this->reviewMode->isOn()) {
            $io->error('Refused: the review accounts exist only in review mode (make review-up).');

            return Command::FAILURE;
        }

        $this->commandBus->dispatch(new SeedReviewAccounts());
        $io->success(\sprintf('/admin and /konto: %s / %s', ReviewMode::LOGIN, ReviewMode::PASSWORD));

        return Command::SUCCESS;
    }
}
