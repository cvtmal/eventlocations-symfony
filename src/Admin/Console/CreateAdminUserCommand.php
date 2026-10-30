<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Admin\Command\CreateAdminUser;
use App\Admin\Exception\AdminUserRuleViolation;
use App\Admin\Security\AdminPasswordHasher;
use App\Admin\ValueObject\AdminRole;
use App\Shared\Uid\EntityId;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creates a staff account; the password is asked twice and never passed as an argument. The user enrols
 * in 2FA at the first login.
 */
#[AsCommand(name: 'app:admin:create-user', description: 'Create a back-office user')]
final class CreateAdminUserCommand
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The e-mail address, used to log in')]
        string $email,
        #[Option('admin or editor')]
        string $role = 'editor',
        #[Option('The display name (default: the part of the e-mail before the @)')]
        ?string $name = null,
    ): int {
        if (\count($this->validator->validate($email, [new Email()])) > 0) {
            $io->error(\sprintf('"%s" is not a valid e-mail address.', $email));

            return Command::FAILURE;
        }

        $adminRole = AdminRole::tryFrom($role);
        if (null === $adminRole) {
            $io->error('The role must be admin or editor.');

            return Command::FAILURE;
        }

        $password = (string) $io->askHidden('Password (at least '.AdminPasswordHasher::MIN_LENGTH.' characters)');
        if ($password !== (string) $io->askHidden('Repeat the password')) {
            $io->error('The two passwords differ.');

            return Command::FAILURE;
        }

        try {
            $this->commandBus->dispatch(new CreateAdminUser(EntityId::generate(), $email, $name ?? explode('@', $email)[0], $adminRole, $password));
        } catch (HandlerFailedException $failure) {
            $io->error($this->translator->trans(AdminUserRuleViolation::unwrap($failure)->translationKey(), [], 'admin'));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Created %s (%s).', mb_strtolower($email), $adminRole->value));

        return Command::SUCCESS;
    }
}
