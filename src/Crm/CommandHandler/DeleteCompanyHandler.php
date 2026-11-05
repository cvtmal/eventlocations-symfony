<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\DeleteCompany;
use App\Crm\Exception\CompanyHasDeals;
use App\Crm\Repository\ActivityRepository;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\DealRepository;
use App\Shared\Messenger\CommandHandlerInterface;

/**
 * The database does the cascade (contacts, timeline links, tasks CASCADE; #55); the Eintrag is freed
 * with the row (#56).
 * The handler then deletes the entries the company was the last object of (D16), so no entry is
 * left on no timeline. A company with a deal stays: one admin click must not erase contract and
 * money history, and `fk_crm_deals_company_id` would answer a 500 (#28's D7).
 */
final class DeleteCompanyHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly ActivityRepository $activities,
        private readonly DealRepository $deals,
    ) {
    }

    public function __invoke(DeleteCompany $command): void
    {
        $company = $this->companies->lock($command->companyId);
        if ($this->deals->existsForCompany($company->id())) {
            throw CompanyHasDeals::forCompany($company->id());
        }
        $activityIds = $this->activities->idsLinkedToCompany($company->id());

        $this->companies->delete($company->id());
        $this->activities->deleteUnlinked($activityIds);
    }
}
