<?php

declare(strict_types=1);

namespace App\Cms\Extension;

use App\Admin\Extension\RecordTab;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The venue editor's own tab. Its priority keeps it first; #25's tabs (Fotos, Räume …) sit below 1000.
 */
final class VenueOverviewRecordTab implements RecordTab
{
    public function recordType(): string
    {
        return 'venue';
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('editor.overview', [], 'cms');
    }

    public function route(): string
    {
        return 'admin_locations_edit';
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
        return 1000;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
