<?php

declare(strict_types=1);

namespace App\Cms\Extension;

use App\Admin\Extension\RecordTab;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The venue's Videos tab (G15), after Dokumente (700).
 */
final class VenueVideosRecordTab implements RecordTab
{
    public function recordType(): string
    {
        return 'venue';
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('videos.tab', [], 'cms');
    }

    public function route(): string
    {
        return 'admin_locations_videos';
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
        return 650;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
