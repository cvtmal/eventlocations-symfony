<?php

declare(strict_types=1);

namespace App\Admin\CommandHandler;

use App\Admin\Command\DeactivateAdminUser;
use App\Admin\Exception\AdminUserRuleViolation;
use App\Admin\Repository\AdminUserRepository;
use App\Admin\ValueObject\AdminRole;
use App\Shared\Messenger\CommandHandlerInterface;

final class DeactivateAdminUserHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly AdminUserRepository $users,
    ) {
    }

    public function __invoke(DeactivateAdminUser $command): void
    {
        if ($command->userId === $command->actingUserId) {
            throw AdminUserRuleViolation::cannotChangeOwnAccount();
        }

        $user = $this->users->get($command->userId);
        $isAnActiveAdmin = AdminRole::Admin === $user->role() && $user->isActive();
        if ($isAnActiveAdmin && $this->users->countActiveAdmins() <= 1) {
            throw AdminUserRuleViolation::lastActiveAdmin();
        }

        $user->deactivate();
    }
}
