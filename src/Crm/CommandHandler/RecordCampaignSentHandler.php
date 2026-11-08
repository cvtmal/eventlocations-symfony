<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\RecordCampaignSent;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\ReadModel\SuppressionQuery;
use App\Shared\Funnel\CampaignCode;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * One UPDATE: the campaign's Locations without a send day get this one, and their `updated_at` moves with it. Only a
 * Location that got a letter counts: on the print list (an Eintrag, no returned letter, not suppressed) and with a
 * live link of the campaign, so the cohort funnel's "Versandt" means sent.
 */
final class RecordCampaignSentHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordCampaignSent $command): int
    {
        if (!CampaignCode::isValid($command->campaignCode)) {
            throw InvalidPropertyValue::unknown('campaign_code');
        }

        return (int) $this->connection->executeStatement(
            'UPDATE crm_companies c SET outreach_sent_on = :day, updated_at = :now
             WHERE c.campaign_code = :code AND c.outreach_sent_on IS NULL AND c.venue_id IS NOT NULL AND c.mail_returned_on IS NULL
               AND NOT '.SuppressionQuery::LOCATION_SUPPRESSED.'
               AND EXISTS (SELECT 1 FROM venue_previews l WHERE l.venue_id = c.venue_id AND l.campaign_code = c.campaign_code AND l.short_code IS NOT NULL AND l.revoked_at IS NULL)',
            ['day' => $command->sentOn->format('Y-m-d'), 'code' => $command->campaignCode, 'now' => $this->clock->now()->format('Y-m-d H:i:sP')],
        );
    }
}
