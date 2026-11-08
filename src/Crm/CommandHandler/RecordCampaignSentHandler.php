<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\RecordCampaignSent;
use App\Crm\Exception\InvalidPropertyValue;
use App\Shared\Funnel\CampaignCode;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\DBAL\Connection;

/**
 * One UPDATE: the campaign's Locations without a send day get this one, and their `updated_at` moves with it.
 */
final class RecordCampaignSentHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(RecordCampaignSent $command): int
    {
        if (!CampaignCode::isValid($command->campaignCode)) {
            throw InvalidPropertyValue::unknown('campaign_code');
        }

        return (int) $this->connection->executeStatement(
            'UPDATE crm_companies SET outreach_sent_on = :day, updated_at = CURRENT_TIMESTAMP WHERE campaign_code = :code AND outreach_sent_on IS NULL',
            ['day' => $command->sentOn->format('Y-m-d'), 'code' => $command->campaignCode],
        );
    }
}
