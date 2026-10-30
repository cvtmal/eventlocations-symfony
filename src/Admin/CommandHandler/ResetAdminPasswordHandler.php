<?php

declare(strict_types=1);

namespace App\Admin\CommandHandler;

use App\Admin\Command\ResetAdminPassword;
use App\Admin\Repository\AdminUserRepository;
use App\Admin\Security\AdminPasswordHasher;
use App\Shared\Messenger\CommandHandlerInterface;

final class ResetAdminPasswordHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly AdminUserRepository $users,
        private readonly AdminPasswordHasher $hasher,
    ) {
    }

    public function __invoke(ResetAdminPassword $command): void
    {
        $this->users->get($command->userId)->changePassword($this->hasher->hash($command->plainPassword));
    }
}
