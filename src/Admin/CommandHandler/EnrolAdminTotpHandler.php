<?php

declare(strict_types=1);

namespace App\Admin\CommandHandler;

use App\Admin\Command\EnrolAdminTotp;
use App\Admin\Repository\AdminUserRepository;
use App\Admin\Security\TotpSecretCipher;
use App\Shared\Messenger\CommandHandlerInterface;

final class EnrolAdminTotpHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly AdminUserRepository $users,
        private readonly TotpSecretCipher $cipher,
    ) {
    }

    public function __invoke(EnrolAdminTotp $command): void
    {
        $this->users->get($command->userId)->enrolTotp($this->cipher->encrypt($command->secret));
    }
}
