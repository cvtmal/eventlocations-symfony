<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\UpdatePortalProfile;
use App\Portal\Repository\PortalUserRepository;
use App\Shared\Messenger\CommandHandlerInterface;

final class UpdatePortalProfileHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly PortalUserRepository $users,
    ) {
    }

    public function __invoke(UpdatePortalProfile $command): void
    {
        $this->users->find($command->id)?->updateProfile($command->firstName, $command->lastName, $command->phone);
    }
}
