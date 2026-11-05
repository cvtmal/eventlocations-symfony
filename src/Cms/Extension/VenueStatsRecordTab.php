<?php

declare(strict_types=1);

namespace App\Cms\Extension;

use App\Admin\Extension\RecordTab;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The venue's Statistik tab (#47), the last one: after Dokumente (700).
 */
final class VenueStatsRecordTab implements RecordTab
{
    public function recordType(): string
    {
        return 'venue';
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('stats.tab', [], 'cms');
    }

    public function route(): string
    {
        return 'admin_locations_stats';
    }

    /**
     * The record id of a venue is its number.
     */
    public function routeParameters(string $recordId): array
    {
        return ['number' => (int) $recordId];
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
