<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\ChangeOutreach;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Repository\CompanyRepository;
use App\Shared\Funnel\CampaignCode;
use App\Shared\Messenger\CommandHandlerInterface;

/**
 * Writes a Location's outreach fields under its row lock. The form checked the campaign code already; the handler
 * checks again, because the code ends up in preview links and the funnel.
 */
final class ChangeOutreachHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
    ) {
    }

    public function __invoke(ChangeOutreach $command): void
    {
        $code = $command->outreach->campaignCode;
        if (null !== $code && !CampaignCode::isValid($code)) {
            throw InvalidPropertyValue::unknown('campaign_code');
        }

        $company = $this->companies->lock($command->companyId);
        if (!$company->outreach()->equals($command->outreach)) {
            $company->changeOutreach($command->outreach);
        }
    }
}
