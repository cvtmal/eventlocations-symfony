<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\RequestPortalPasswordReset;
use App\Portal\Mailer\PortalMailer;
use App\Portal\Repository\PortalUserRepository;
use App\Shared\Messenger\CommandHandlerInterface;

final class RequestPortalPasswordResetHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly PortalUserRepository $users,
        private readonly PortalMailer $mailer,
    ) {
    }

    public function __invoke(RequestPortalPasswordReset $command): void
    {
        $user = $this->users->findOneByEmail($command->email);
        if (true === $user?->isActive()) {
            $this->mailer->sendPasswordReset($user);
        }
    }
}
