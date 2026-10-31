<?php

declare(strict_types=1);

namespace App\Cms\Extension;

use App\Admin\Extension\RecordTab;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The venue's Bewertungen tab (#25), after Übersicht (1000); Fotos 900, Räume 800, Dokumente 700, Bewertungen 600.
 */
final class VenueReviewsRecordTab implements RecordTab
{
    public function recordType(): string
    {
        return 'venue';
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('reviews.tab', [], 'cms');
    }

    public function route(): string
    {
        return 'admin_locations_reviews';
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
