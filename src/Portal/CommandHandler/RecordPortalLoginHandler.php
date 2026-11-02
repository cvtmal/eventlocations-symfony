<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\RecordPortalLogin;
use App\Portal\Repository\PortalUserRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Psr\Clock\ClockInterface;

final class RecordPortalLoginHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly PortalUserRepository $users,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordPortalLogin $command): void
    {
        $this->users->find($command->id)?->recordLogin($this->clock->now());
    }
}
