<?php

declare(strict_types=1);

namespace App\Cms\Extension;

use App\Admin\Extension\RecordTab;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The venue's Fotos tab (#25), after Übersicht (1000); Fotos 900, Räume 800, Dokumente 700 (#53 retired Bewertungen, 600).
 */
final class VenuePhotosRecordTab implements RecordTab
{
    public function recordType(): string
    {
        return 'venue';
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('photos.tab', [], 'cms');
    }

    public function route(): string
    {
        return 'admin_locations_photos';
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
        return 900;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
