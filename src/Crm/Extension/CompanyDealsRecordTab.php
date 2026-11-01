<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\RecordTab;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * All of the company's deals (C:2099); the record's card shows five.
 */
final class CompanyDealsRecordTab implements RecordTab
{
    public function recordType(): string
    {
        return 'company';
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('record.tab.deals', [], 'crm');
    }

    public function route(): string
    {
        return 'admin_crm_companies_deals';
    }

    public function routeParameters(string $recordId): array
    {
        return ['id' => $recordId];
    }

    public function priority(): int
    {
        return 500;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
