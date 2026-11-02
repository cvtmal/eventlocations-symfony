<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\ChangePortalPassword;
use App\Portal\Repository\PortalUserRepository;
use App\Portal\Security\PortalPasswordHasher;
use App\Shared\Messenger\CommandHandlerInterface;

/**
 * The new hash changes the identity's fingerprint, which ends every session of this account, the current one too.
 */
final class ChangePortalPasswordHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly PortalUserRepository $users,
        private readonly PortalPasswordHasher $hasher,
    ) {
    }

    public function __invoke(ChangePortalPassword $command): void
    {
        $this->users->find($command->id)?->changePassword($this->hasher->hash($command->plainPassword));
    }
}
