<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Stamps the send day on every Location of a postal campaign that has none yet (G11): the letters went out. A Location
 * with its own send day keeps it. The handler answers how many it stamped.
 */
final readonly class RecordCampaignSent implements AuditableCommand
{
    public function __construct(
        public string $campaignCode,
        public \DateTimeImmutable $sentOn,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('campaign', $this->campaignCode);
    }

    public function auditSummary(): array
    {
        return ['sent_on' => $this->sentOn->format('Y-m-d')];
    }
}
