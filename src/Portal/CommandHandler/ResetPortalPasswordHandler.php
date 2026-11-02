<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\ResetPortalPassword;
use App\Portal\Repository\PortalUserRepository;
use App\Portal\Security\PortalPasswordHasher;
use App\Shared\Messenger\CommandHandlerInterface;
use Psr\Clock\ClockInterface;

/**
 * The one way a password gets set from a mail, on registering and after a forgotten one alike.
 */
final class ResetPortalPasswordHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly PortalUserRepository $users,
        private readonly PortalPasswordHasher $hasher,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(ResetPortalPassword $command): void
    {
        $this->users->find($command->id)?->setPasswordFromMail($this->hasher->hash($command->plainPassword), $this->clock->now());
    }
}
