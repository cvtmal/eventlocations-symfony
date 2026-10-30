<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Admin\Command\ResetAdminTotp;
use App\Admin\ReadModel\AdminUserListQuery;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * For a lost phone: the user enrols again at the next login.
 */
#[AsCommand(name: 'app:admin:reset-2fa', description: 'Reset the 2FA of a back-office user')]
final class ResetAdminTotpCommand
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly AdminUserListQuery $users,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The user\'s e-mail address')]
        string $email,
    ): int {
        $id = $this->users->idByEmail($email);
        if (null === $id) {
            $io->error(\sprintf('There is no user "%s".', $email));

            return Command::FAILURE;
        }

        $this->commandBus->dispatch(new ResetAdminTotp($id));
        $io->success(\sprintf('2FA reset for %s.', mb_strtolower($email)));

        return Command::SUCCESS;
    }
}
