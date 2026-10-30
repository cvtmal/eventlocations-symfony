<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Admin\Command\ResetAdminPassword;
use App\Admin\Exception\AdminUserRuleViolation;
use App\Admin\ReadModel\AdminUserListQuery;
use App\Admin\Security\AdminPasswordHasher;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'app:admin:reset-password', description: 'Set a new password for a back-office user')]
final class ResetAdminPasswordCommand
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly AdminUserListQuery $users,
        private readonly TranslatorInterface $translator,
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

        $password = (string) $io->askHidden('New password (at least '.AdminPasswordHasher::MIN_LENGTH.' characters)');
        if ($password !== (string) $io->askHidden('Repeat the password')) {
            $io->error('The two passwords differ.');

            return Command::FAILURE;
        }

        try {
            $this->commandBus->dispatch(new ResetAdminPassword($id, $password));
        } catch (HandlerFailedException $failure) {
            $io->error($this->translator->trans(AdminUserRuleViolation::unwrap($failure)->translationKey(), [], 'admin'));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Password changed for %s.', mb_strtolower($email)));

        return Command::SUCCESS;
    }
}
