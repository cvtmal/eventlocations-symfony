<?php

declare(strict_types=1);

namespace App\Admin\CommandHandler;

use App\Admin\Command\CreateAdminUser;
use App\Admin\Entity\AdminUser;
use App\Admin\Exception\AdminUserRuleViolation;
use App\Admin\Repository\AdminUserRepository;
use App\Admin\Security\AdminPasswordHasher;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class CreateAdminUserHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly AdminUserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly AdminPasswordHasher $hasher,
    ) {
    }

    public function __invoke(CreateAdminUser $command): void
    {
        if ($this->users->emailExists($command->email)) {
            throw AdminUserRuleViolation::emailTaken();
        }

        $this->em->persist(new AdminUser(
            $command->id,
            $command->email,
            $command->displayName,
            $this->hasher->hash($command->plainPassword),
            $command->role,
        ));

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw AdminUserRuleViolation::emailTaken();
        }
    }
}
