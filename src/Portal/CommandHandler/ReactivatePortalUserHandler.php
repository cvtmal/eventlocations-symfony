<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\ReactivatePortalUser;
use App\Portal\Repository\PortalUserRepository;
use App\Shared\Messenger\CommandHandlerInterface;

final class ReactivatePortalUserHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly PortalUserRepository $users,
    ) {
    }

    public function __invoke(ReactivatePortalUser $command): void
    {
        $this->users->get($command->id)->reactivate();
    }
}
