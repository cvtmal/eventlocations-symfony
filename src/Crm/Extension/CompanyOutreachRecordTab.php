<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\RecordTab;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The Location's "Akquise" tab (G11): campaign, cohort, channel, basis, send and return days, and the suppression.
 */
final class CompanyOutreachRecordTab implements RecordTab
{
    public function recordType(): string
    {
        return 'company';
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('record.tab.outreach', [], 'crm');
    }

    public function route(): string
    {
        return 'admin_crm_companies_outreach';
    }

    public function routeParameters(string $recordId): array
    {
        return ['id' => $recordId];
    }

    public function priority(): int
    {
        return 400;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
