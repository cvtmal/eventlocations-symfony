<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalVenueAccessFactory;
use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Tests\Application\Cms\SendsVenueMediaRequests;
use App\Tests\Support\Portal\PortalSession;
use App\Venues\Command\AddVenuePhoto;
use App\Venues\Entity\Venue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The manager's photo endpoints (POR-8): the bodies and answers of staff's API, gallery only, behind the voter, the
 * `portal_api` token and the `portal_upload` limiter.
 */
final class LocationPhotosApiTest extends WebTestCase
{
    use Factories;
    use PortalSession {
        tearDown as private endPortalSession;
    }
    use SendsVenueMediaRequests;

    private const string RATE_LIMITED = 'Sie haben in der letzten Stunde sehr viele Fotos hochgeladen. Bitte versuchen Sie es später noch einmal.';

    protected function tearDown(): void
    {
        $this->removeVenueMedia();
        $this->endPortalSession();
    }

    public function test_the_page_mounts_the_island_without_rooms(): void
    {
        $venue = $this->managedVenue();
        RoomFactory::createOne(['venue' => $venue]);

        $crawler = $this->client->request('GET', '/konto/locations/'.$venue->number().'/fotos');

        self::assertResponseIsSuccessful();
        $props = json_decode((string) $crawler->filter('[data-island="photo-manager"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);
        self::assertIsArray($props['endpoints']);
        self::assertSame(['upload', 'order', 'alt', 'remove'], array_keys($props['endpoints']));
        self::assertSame([], $props['rooms']);
        self::assertSame('/konto/locations/'.$venue->number().'/fotos', $props['reloadUrl']);
    }

    public function test_an_upload_lands_in_the_gallery_whatever_the_body_says(): void
    {
        $venue = $this->managedVenue();
        $room = RoomFactory::createOne(['venue' => $venue]);

        $this->upload(self::url($venue), $this->photoFile(), ['roomId' => $room->id()], $this->csrfToken());

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $answer = $this->answer();
        self::assertFalse($answer['duplicate']);
        self::assertIsArray($answer['photos']);
        self::assertCount(1, $answer['photos']);
        self::assertIsArray($answer['photos'][0]);
        self::assertSame($answer['photo'], $answer['photos'][0]['id']);
        self::assertNull($answer['photos'][0]['roomId']);
        self::assertNull($this->connection->fetchOne('SELECT room_id FROM venue_photos WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_an_order_stays_in_the_gallery_whatever_the_body_says(): void
    {
        $venue = $this->managedVenue();
        $room = RoomFactory::createOne(['venue' => $venue]);
        $first = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $second = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2]);
        $inRoom = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room, 'position' => 1]);

        $this->json('PUT', self::url($venue).'/order', ['roomId' => $room->id(), 'ids' => [$second->id(), $first->id()]], $this->csrfToken());

        self::assertResponseIsSuccessful();
        self::assertSame(
            [$second->id(), $first->id()],
            $this->connection->fetchFirstColumn('SELECT id FROM venue_photos WHERE venue_id = ? AND room_id IS NULL ORDER BY position', [$venue->id()]),
        );
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_photos WHERE room_id = ? AND id = ?', [$room->id(), $inRoom->id()]));
    }

    public function test_an_alt_text_is_saved_with_the_photo_token(): void
    {
        $venue = $this->managedVenue();
        $token = $this->csrfToken();
        $this->upload(self::url($venue), $this->photoFile(), token: $token);
        $photos = $this->answer()['photos'];
        self::assertIsArray($photos);
        self::assertIsArray($photos[0]);

        $this->json('PUT', self::url($venue).'/'.$photos[0]['id'].'/alt', ['loadedAt' => $photos[0]['token'], 'alts' => ['de' => 'Der Saal am Abend']], $token);

        self::assertResponseIsSuccessful();
        $photos = $this->answer()['photos'];
        self::assertIsArray($photos);
        self::assertIsArray($photos[0]);
        self::assertSame(['de' => 'Der Saal am Abend'], $photos[0]['alts']);
    }

    public function test_a_photo_is_removed(): void
    {
        $venue = $this->managedVenue();
        $first = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $second = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2]);

        $this->json('DELETE', self::url($venue).'/'.$second->id(), token: $this->csrfToken());

        self::assertResponseIsSuccessful();
        self::assertSame([$first->id()], $this->connection->fetchFirstColumn('SELECT id FROM venue_photos WHERE venue_id = ?', [$venue->id()]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writes(): iterable
    {
        yield 'add' => ['add'];
        yield 'order' => ['order'];
        yield 'alt' => ['alt'];
        yield 'remove' => ['remove'];
    }

    #[DataProvider('writes')]
    public function test_without_the_token_a_write_is_a_json_403_and_changes_nothing(string $write): void
    {
        $venue = $this->managedVenue();
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $url = self::url($venue);
        $before = $this->photoRows($venue);

        match ($write) {
            'add' => $this->upload($url, $this->photoFile()),
            'order' => $this->json('PUT', $url.'/order', ['ids' => [$photo->id()]]),
            'alt' => $this->json('PUT', $url.'/'.$photo->id().'/alt', ['loadedAt' => '1', 'alts' => ['de' => 'Später']]),
            'remove' => $this->json('DELETE', $url.'/'.$photo->id()),
            default => self::fail('Unknown write '.$write),
        };

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame($before, $this->photoRows($venue));
    }

    public function test_a_file_that_is_no_photo_is_refused(): void
    {
        $venue = $this->managedVenue();

        $this->upload(self::url($venue), $this->photoFile('%PDF-1.7 kein Foto', 'foto.jpg', 'image/jpeg'), token: $this->csrfToken());

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['file' => ['Bitte ein Foto als JPEG, PNG oder WebP hochladen.']], $this->errors());
        self::assertSame([], $this->photoRows($venue));
    }

    public function test_the_upload_after_the_last_allowed_photo_is_refused(): void
    {
        $venue = $this->managedVenue();
        for ($position = 1; $position <= AddVenuePhoto::MAX_PHOTOS; ++$position) {
            VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $position]);
        }

        $this->upload(self::url($venue), $this->photoFile(), token: $this->csrfToken());

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertArrayHasKey('file', $this->errors());
        self::assertCount(AddVenuePhoto::MAX_PHOTOS, $this->photoRows($venue));
    }

    public function test_the_upload_limit_is_a_plain_422_per_manager(): void
    {
        $manager = $this->createManager();
        $venue = VenueFactory::new()->withoutLocation()->create();
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);
        $this->logInAsManager($manager);
        $this->limiter()->create($manager->id())->consume(60);

        $this->upload(self::url($venue), $this->photoFile(), token: $this->csrfToken());

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['file' => [self::RATE_LIMITED]], $this->errors());
        self::assertSame([], $this->photoRows($venue));

        $second = $this->createManager();
        PortalVenueAccessFactory::new()->active()->create(['user' => $second, 'venue' => $venue]);
        $this->client->getCookieJar()->clear();
        $this->logInAsManager($second);

        $this->upload(self::url($venue), $this->photoFile(), token: $this->csrfToken());

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function test_a_valid_token_on_another_managers_location_is_a_json_403(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        PortalVenueAccessFactory::new()->active()->create(['venue' => $venue]);
        $this->logInAsManager();

        $this->upload(self::url($venue), $this->photoFile(), token: $this->csrfToken());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame([], $this->photoRows($venue));
    }

    /**
     * The voter sees `number` only, so the walker cannot prove this: the handlers look the photo up inside the venue.
     */
    public function test_a_photo_of_another_location_is_a_404_and_stays_as_it_is(): void
    {
        $venue = $this->managedVenue();
        $other = VenueFactory::new()->withoutLocation()->create();
        $photo = VenuePhotoFactory::createOne(['venue' => $other, 'position' => 1]);
        $before = $this->photoRows($other);
        $token = $this->csrfToken();

        $this->json('PUT', self::url($venue).'/'.$photo->id().'/alt', ['loadedAt' => '1', 'alts' => ['de' => 'Fremder Text']], $token);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->json('DELETE', self::url($venue).'/'.$photo->id(), token: $token);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame($before, $this->photoRows($other));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_photo_translations WHERE venue_photo_id = ?', [$photo->id()]));
    }

    /**
     * A real page's token, as the island reads it. Beats the trait's, which reads `/admin`.
     */
    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/konto');

        return (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
    }

    private function managedVenue(): Venue
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        PortalVenueAccessFactory::new()->active()->create(['user' => $this->logInAsManager(), 'venue' => $venue]);

        return $venue;
    }

    private function limiter(): RateLimiterFactoryInterface
    {
        $limiter = self::getContainer()->get('limiter.portal_upload');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiter);

        return $limiter;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function photoRows(Venue $venue): array
    {
        return $this->connection->fetchAllAssociative('SELECT id, room_id, position FROM venue_photos WHERE venue_id = ? ORDER BY position', [$venue->id()]);
    }

    private static function url(Venue $venue): string
    {
        return '/konto/api/locations/'.$venue->number().'/photos';
    }
}
