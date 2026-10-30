<?php

declare(strict_types=1);

namespace App\Admin\CommandHandler;

use App\Admin\Command\ChangeAdminRole;
use App\Admin\Exception\AdminUserRuleViolation;
use App\Admin\Repository\AdminUserRepository;
use App\Admin\ValueObject\AdminRole;
use App\Shared\Messenger\CommandHandlerInterface;

final class ChangeAdminRoleHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly AdminUserRepository $users,
    ) {
    }

    public function __invoke(ChangeAdminRole $command): void
    {
        if ($command->userId === $command->actingUserId) {
            throw AdminUserRuleViolation::cannotChangeOwnAccount();
        }

        $user = $this->users->get($command->userId);
        $demotesAnActiveAdmin = AdminRole::Admin === $user->role() && AdminRole::Admin !== $command->role && $user->isActive();
        if ($demotesAnActiveAdmin && $this->users->countActiveAdmins() <= 1) {
            throw AdminUserRuleViolation::lastActiveAdmin();
        }

        $user->changeRole($command->role);
    }
}
