<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\RecordPanel;
use App\Crm\ReadModel\VenueCompanyQuery;

/**
 * The CRM card on a venue (`### 27.`, D20): Cms never sees Crm, the panel finds its place. The record
 * id of a venue is its number; anything else (`ExtensionPointsTest` probes with a UUID) gives an
 * empty context, and the template then renders nothing. Crm may not name Cms's `VenueNumber`, so the
 * pattern is repeated here.
 */
final readonly class VenueCrmPanel implements RecordPanel
{
    private const string NUMBER = '/^[1-9]\d{0,8}$/D';

    public function __construct(
        private VenueCompanyQuery $companies,
    ) {
    }

    public function recordType(): string
    {
        return 'venue';
    }

    public function template(): string
    {
        return 'admin/crm/_venue_panel.html.twig';
    }

    public function context(string $recordId): array
    {
        if (1 !== preg_match(self::NUMBER, $recordId)) {
            return [];
        }

        return ['crm_venue' => $this->companies->forVenue((int) $recordId)];
    }

    public function priority(): int
    {
        return 100;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
