<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\RecordTab;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Übersicht, the record itself. Without it the tab bar would have no way back from another tab.
 */
final class CompanyOverviewRecordTab implements RecordTab
{
    public function recordType(): string
    {
        return 'company';
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('record.tab.overview', [], 'crm');
    }

    public function route(): string
    {
        return 'admin_crm_companies_show';
    }

    public function routeParameters(string $recordId): array
    {
        return ['id' => $recordId];
    }

    public function priority(): int
    {
        return 1000;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
