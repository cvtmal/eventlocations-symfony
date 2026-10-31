<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\DeleteCompany;
use App\Crm\Repository\ActivityRepository;
use App\Crm\Repository\CompanyRepository;
use App\Shared\Messenger\CommandHandlerInterface;

/**
 * The database does the cascade (contacts SET NULL; venue links, timeline links, tasks CASCADE).
 * The handler then deletes the entries the company was the last object of (D16), so no entry is
 * left on no timeline.
 */
final class DeleteCompanyHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly ActivityRepository $activities,
    ) {
    }

    public function __invoke(DeleteCompany $command): void
    {
        $company = $this->companies->lock($command->companyId);
        $activityIds = $this->activities->idsLinkedToCompany($company->id());

        $this->companies->delete($company->id());
        $this->activities->deleteUnlinked($activityIds);
    }
}
