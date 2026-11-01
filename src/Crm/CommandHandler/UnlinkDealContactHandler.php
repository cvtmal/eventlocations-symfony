<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\UnlinkDealContact;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\DealRepository;
use App\Shared\Messenger\CommandHandlerInterface;

final class UnlinkDealContactHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly DealRepository $deals,
    ) {
    }

    public function __invoke(UnlinkDealContact $command): void
    {
        $this->companies->lock($this->deals->companyIdOf($command->dealId));
        $deal = $this->deals->lock($command->dealId);

        $this->deals->unlinkContact($deal->id(), $command->contactId);
    }
}
