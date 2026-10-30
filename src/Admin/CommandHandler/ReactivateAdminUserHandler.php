<?php

declare(strict_types=1);

namespace App\Admin\CommandHandler;

use App\Admin\Command\ReactivateAdminUser;
use App\Admin\Repository\AdminUserRepository;
use App\Shared\Messenger\CommandHandlerInterface;

final class ReactivateAdminUserHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly AdminUserRepository $users,
    ) {
    }

    public function __invoke(ReactivateAdminUser $command): void
    {
        $this->users->get($command->userId)->reactivate();
    }
}
