<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\UnlinkVenue;
use App\Crm\Repository\CompanyRepository;
use App\Shared\Messenger\CommandHandlerInterface;

final class UnlinkVenueHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
    ) {
    }

    public function __invoke(UnlinkVenue $command): void
    {
        $company = $this->companies->forVenue($command->venueId);
        if (null === $company || $company->id() !== $command->companyId) {
            return;
        }

        $company->releaseVenue();
    }
}
