<?php

declare(strict_types=1);

namespace App\Story;

use App\Factory\VenueFactory;
use App\Factory\VenuePreviewFactory;
use App\Factory\VenueTranslationFactory;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Zenstruck\Foundry\Story;

/**
 * G5: one prepared draft, "Remise am Bach" (unpublished, no place and no photos yet, so the preview shows the
 * placeholder), with an open preview link: its Vorschau-Links tab in /admin shows the address and the QR code.
 * DemoStory adds an open and a revoked link to venue 2, whose ungranted photo the preview hides. Skips when its
 * campaign is already there, so a second `make seed` writes nothing.
 */
final class PreviewStory extends Story
{
    private const string CAMPAIGN = 'demo-entwurf';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function build(): void
    {
        if (false !== $this->connection->fetchOne('SELECT 1 FROM venue_previews WHERE campaign_code = ? LIMIT 1', [self::CAMPAIGN])) {
            return;
        }

        $venue = VenueFactory::new()->withoutLocation()->create([
            'name' => 'Remise am Bach',
            'tier' => KnownTiers::FREE,
            'status' => VenueStatus::Unpublished,
            'capacityMin' => null,
            'capacityMax' => 80,
        ]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => 'remise-am-bach', 'summary' => 'Eine Remise am Bach für Seminare und Apéros.']);
        VenuePreviewFactory::createOne(['venue' => $venue, 'campaignCode' => self::CAMPAIGN]);
    }
}
