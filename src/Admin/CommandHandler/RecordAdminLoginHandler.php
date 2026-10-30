<?php

declare(strict_types=1);

namespace App\Admin\CommandHandler;

use App\Admin\Command\RecordAdminLogin;
use App\Admin\Repository\AdminUserRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Psr\Clock\ClockInterface;

final class RecordAdminLoginHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly AdminUserRepository $users,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordAdminLogin $command): void
    {
        $this->users->get($command->userId)->recordLogin($this->clock->now());
    }
}
