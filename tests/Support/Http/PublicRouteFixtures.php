<?php

declare(strict_types=1);

namespace App\Tests\Support\Http;

use App\Factory\VenueSlugHistoryFactory;
use App\Geo\ValueObject\Canton;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Inquiries\InquiryFormFields;
use App\Tests\Support\Search\LandingFixtures;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The data every public route answers from: a rich venue with a history slug, an unpublished one,
 * and live canton, town and building pages. Run it inside the test's rolled-back transaction.
 * The clock freezes two hours before a Zurich midnight, so a listing's lifetime is known.
 */
trait PublicRouteFixtures
{
    use ImportsTownDataset;
    use LandingFixtures;

    private const FROZEN_NOW = '2026-10-30 22:00:00 Europe/Zurich';
    private const FILL_STARTED = '2026-10-30 21:58:00 Europe/Zurich';
    private const HISTORY_SLUG = 'altes-schloss-seeblick';

    /**
     * @return array{number: int, gone: string, town: string, building: string, started: string}
     */
    private function seedPublicRoutes(): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $venue = DetailVenues::full($em);
        VenueSlugHistoryFactory::createOne(['venue' => $venue, 'slug' => self::HISTORY_SLUG]);
        $this->withBuilding($this->inCanton(Canton::ZH, 5), 'castle');
        $this->inTown('Stäfa', 5);
        $gone = $this->inCanton(Canton::BE, 1, VenueStatus::Unpublished)[0];

        foreach (['cache.phone_reveal', 'cache.inquiry_submit'] as $name) {
            $pool = self::getContainer()->get($name);
            self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
            $pool->clear();
        }

        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return [
            'number' => $venue->number(),
            'gone' => (string) $em->getConnection()->fetchOne('SELECT slug FROM venue_translations WHERE venue_id = ?', [$gone->id()]),
            'town' => $this->town('Stäfa')->slug(),
            'building' => $translator->trans('landing.building.castle.slug', [], 'search'),
            'started' => InquiryFormFields::tokenFrom(self::FILL_STARTED),
        ];
    }
}
