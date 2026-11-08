<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\SuppressCompany;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\SuppressionRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Psr\Clock\ClockInterface;

/**
 * Suppresses the Location and each address it holds, under the Location's lock so that no contact is added halfway.
 * A later address of the Location is covered by the Location's own row (`SuppressionQuery::LOCATION_SUPPRESSED`).
 */
final class SuppressCompanyHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly SuppressionRepository $suppressions,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(SuppressCompany $command): bool
    {
        $company = $this->companies->lock($command->companyId);
        $now = $this->clock->now();

        $new = $this->suppressions->addCompany($company->id(), $command->reason, $now);
        foreach ($this->suppressions->addressesOf($company->id()) as $email) {
            $new = $this->suppressions->addEmail($email, $command->reason, $now) || $new;
        }

        return $new;
    }
}
