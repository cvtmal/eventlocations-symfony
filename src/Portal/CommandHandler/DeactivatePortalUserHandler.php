<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\DeactivatePortalUser;
use App\Portal\Repository\PortalUserRepository;
use App\Shared\Messenger\CommandHandlerInterface;

final class DeactivatePortalUserHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly PortalUserRepository $users,
    ) {
    }

    public function __invoke(DeactivatePortalUser $command): void
    {
        $this->users->get($command->id)->deactivate();
    }
}
