<?php

declare(strict_types=1);

namespace App\Tests\Application\Venues;

use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Http\WixImageResponses;
use App\Tests\Support\Venues\DetailVenues;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * D12, places-photos: without GOOGLE_PLACES_PHOTOS_KEY (production until the owner sets it) the page renders no
 * section and neither route asks Google. The key is emptied before the client boots, as in VenueGoogleRatingTest.
 */
final class VenueGooglePhotosKeylessTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const string KEY = 'GOOGLE_PLACES_PHOTOS_KEY';

    public function test_without_a_key_nothing_renders_and_nothing_goes_to_google(): void
    {
        $saved = [$_SERVER[self::KEY] ?? null, $_ENV[self::KEY] ?? null];
        $_SERVER[self::KEY] = $_ENV[self::KEY] = '';
        $client = self::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->beginTransaction();
        try {
            $venue = DetailVenues::minimal();
            $em->flush();
            $em->getConnection()->executeStatement("UPDATE venues SET google_place_id = 'ChIJN1t_tDeuEmsRUsoyG83frY4' WHERE id = ?", [$venue->id()]);

            $crawler = $client->request('GET', '/eventlokal/'.DetailVenues::MINIMAL_SLUG);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('.venue-placeholder > .photo-placeholder'), 'F6 placeholder');
            self::assertCount(0, $crawler->filter('[data-google-photos]'));

            $client->request('GET', '/eventlokal/'.DetailVenues::MINIMAL_SLUG.'/google-fotos');
            self::assertResponseStatusCodeSame(204);
            $client->request('GET', '/eventlokal/'.DetailVenues::MINIMAL_SLUG.'/google-fotos/REF_one');
            self::assertResponseStatusCodeSame(204);

            $google = self::getContainer()->get(WixImageResponses::class);
            self::assertInstanceOf(WixImageResponses::class, $google);
            self::assertSame([], $google->requested());
        } finally {
            $em->clear();
            $em->getConnection()->rollBack();
            [$_SERVER[self::KEY], $_ENV[self::KEY]] = $saved;
        }
    }
}
