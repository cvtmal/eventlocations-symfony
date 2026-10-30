<?php

declare(strict_types=1);

namespace App\Admin\CommandHandler;

use App\Admin\Command\ResetAdminTotp;
use App\Admin\Repository\AdminUserRepository;
use App\Shared\Messenger\CommandHandlerInterface;

final class ResetAdminTotpHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly AdminUserRepository $users,
    ) {
    }

    public function __invoke(ResetAdminTotp $command): void
    {
        $this->users->get($command->userId)->resetTotp();
    }
}
