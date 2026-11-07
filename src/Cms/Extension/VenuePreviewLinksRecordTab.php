<?php

declare(strict_types=1);

namespace App\Cms\Extension;

use App\Admin\Extension\RecordTab;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The entry's Vorschau-Links tab (G5): after Dokumente (700), before Statistik (500).
 */
final class VenuePreviewLinksRecordTab implements RecordTab
{
    public function recordType(): string
    {
        return 'venue';
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('preview_links.tab', [], 'cms');
    }

    public function route(): string
    {
        return 'admin_locations_preview_links';
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
        return 600;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
