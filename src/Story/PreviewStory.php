<?php

declare(strict_types=1);

namespace App\Story;

use App\Factory\ClaimChallengeFactory;
use App\Factory\PhotoCandidateFactory;
use App\Factory\SourceRecordFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePreviewFactory;
use App\Factory\VenueTranslationFactory;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\CandidateOrigin;
use App\Venues\ValueObject\ImageCandidate;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Zenstruck\Foundry\Story;

/**
 * G5: one prepared draft, "Remise am Bach" (unpublished, no place and no photos yet, so the preview shows the
 * placeholder), with an open preview link: its Vorschau-Links tab in /admin shows the address and the QR code.
 * DemoStory adds an open and a revoked link to venue 2, whose ungranted photo the preview hides. Skips when its
 * campaign is already there, so a second `make seed` writes nothing.
 *
 * G6: the draft has a website (`www.remise-am-bach.example`), so a claim with an address `@remise-am-bach.example`
 * gets access at once (the code is in Mailpit), two photo candidates for the claim's photo step (their URLs lead
 * nowhere, so a ticked one stays a candidate) and one expired claim challenge.
 */
final class PreviewStory extends Story
{
    private const string CAMPAIGN = 'demo-entwurf';
    private const string WEBSITE = 'https://www.remise-am-bach.example/';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function build(): void
    {
        if (false !== $this->connection->fetchOne('SELECT 1 FROM venue_previews WHERE campaign_code = ? LIMIT 1', [self::CAMPAIGN])) {
            return;
        }

        $venue = VenueFactory::new()->withoutLocation()->afterInstantiate(static function (Venue $venue): void {
            $venue->changeContact(null, null, self::WEBSITE, null);
        })->create([
            'name' => 'Remise am Bach',
            'tier' => KnownTiers::FREE,
            'status' => VenueStatus::Unpublished,
            'capacityMin' => null,
            'capacityMax' => 80,
        ]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => 'remise-am-bach', 'summary' => 'Eine Remise am Bach für Seminare und Apéros.']);
        VenuePreviewFactory::createOne(['venue' => $venue, 'campaignCode' => self::CAMPAIGN]);

        $record = SourceRecordFactory::createOne(['venue' => $venue, 'url' => self::WEBSITE, 'facts' => [], 'report' => ['final_url' => self::WEBSITE]]);
        foreach ([
            new ImageCandidate(self::WEBSITE.'bilder/remise.jpg', self::WEBSITE, CandidateOrigin::OgImage, 'Die Remise', null, 1600, 1067),
            new ImageCandidate(self::WEBSITE.'bilder/bach.jpg', self::WEBSITE.'galerie', CandidateOrigin::Img, 'Der Bach', null, 1200, 800),
        ] as $position => $image) {
            PhotoCandidateFactory::createOne(['sourceRecord' => $record, 'image' => $image, 'position' => $position + 1]);
        }
        ClaimChallengeFactory::createOne(['venue' => $venue, 'at' => new \DateTimeImmutable('@'.(time() - 3600))]);
    }
}
