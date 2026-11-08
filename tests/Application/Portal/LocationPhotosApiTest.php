<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalVenueAccessFactory;
use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Tests\Application\Cms\SendsVenueMediaRequests;
use App\Tests\Support\Portal\PortalSession;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The manager's photo endpoints (POR-8): the bodies and answers of staff's API, gallery and rooms (F11), behind the
 * voter, the `portal_api` token and the `portal_upload` limiter.
 */
final class LocationPhotosApiTest extends WebTestCase
{
    use Factories;
    use PortalSession {
        tearDown as private endPortalSession;
    }
    use SendsVenueMediaRequests;

    private const string RATE_LIMITED = 'Du hast in der letzten Stunde sehr viele Fotos hochgeladen. Bitte versuch es später noch einmal.';

    /** `photos_max` of the seeded Free plan. */
    private const int FREE_PHOTOS = 6;

    protected function tearDown(): void
    {
        $this->removeVenueMedia();
        $this->endPortalSession();
    }

    public function test_the_page_mounts_the_island_with_the_rooms(): void
    {
        $venue = $this->managedVenue();
        $room = RoomFactory::createOne(['venue' => $venue]);

        $crawler = $this->client->request('GET', '/konto/locations/'.$venue->number().'/fotos');

        self::assertResponseIsSuccessful();
        $props = json_decode((string) $crawler->filter('[data-island="photo-manager"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);
        self::assertIsArray($props['endpoints']);
        self::assertSame(['upload', 'order', 'alt', 'room', 'remove'], array_keys($props['endpoints']));
        self::assertIsArray($props['rooms']);
        self::assertCount(1, $props['rooms']);
        self::assertIsArray($props['rooms'][0]);
        self::assertSame($room->id(), $props['rooms'][0]['id']);
        self::assertSame('/konto/locations/'.$venue->number().'/fotos', $props['reloadUrl']);
        self::assertSelectorExists('aside #location-plan');
    }

    public function test_an_upload_lands_in_the_room_the_body_names(): void
    {
        $venue = $this->managedVenue();
        $room = RoomFactory::createOne(['venue' => $venue]);

        $this->upload(self::url($venue), $this->photoFile(), ['roomId' => $room->id()], $this->csrfToken());

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame($room->id(), $this->connection->fetchOne('SELECT room_id FROM venue_photos WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_an_upload_to_a_room_of_another_location_stores_nothing(): void
    {
        $venue = $this->managedVenue();
        $foreign = RoomFactory::createOne(['venue' => VenueFactory::new()->withoutLocation()->create()]);

        $this->upload(self::url($venue), $this->photoFile(), ['roomId' => $foreign->id()], $this->csrfToken());

        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_photos WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_a_room_order_and_a_move_to_a_room_are_saved(): void
    {
        $venue = $this->managedVenue();
        $room = RoomFactory::createOne(['venue' => $venue]);
        $gallery = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $first = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room, 'position' => 1]);
        $second = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room, 'position' => 2]);
        $token = $this->csrfToken();

        $this->json('PUT', self::url($venue).'/order', ['roomId' => $room->id(), 'ids' => [$second->id(), $first->id()]], $token);
        self::assertResponseIsSuccessful();
        $this->json('PUT', self::url($venue).'/'.$gallery->id().'/room', ['roomId' => $room->id()], $token);
        self::assertResponseIsSuccessful();

        self::assertSame(
            [$second->id(), $first->id(), $gallery->id()],
            $this->connection->fetchFirstColumn('SELECT id FROM venue_photos WHERE room_id = ? ORDER BY position', [$room->id()]),
        );
    }

    public function test_a_move_to_a_room_of_another_location_is_refused(): void
    {
        $venue = $this->managedVenue();
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $foreign = RoomFactory::createOne(['venue' => VenueFactory::new()->withoutLocation()->create()]);

        $this->json('PUT', self::url($venue).'/'.$photo->id().'/room', ['roomId' => $foreign->id()], $this->csrfToken());

        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->connection->fetchOne('SELECT room_id FROM venue_photos WHERE id = ?', [$photo->id()]));
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
        yield 'room' => ['room'];
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
            'room' => $this->json('PUT', $url.'/'.$photo->id().'/room', ['roomId' => RoomFactory::createOne(['venue' => $venue])->id()]),
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

    /**
     * F12: the limit is the plan's, for the whole location. Free holds six photos, so the seventh is refused with a
     * message that names the plan and the limit.
     */
    public function test_the_upload_after_the_last_photo_of_the_free_plan_is_refused(): void
    {
        $venue = $this->managedVenue();
        $this->fillGallery($venue, self::FREE_PHOTOS);

        $this->upload(self::url($venue), $this->photoFile(), token: $this->csrfToken());

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $errors = $this->errors();
        self::assertSame(['file'], array_keys($errors));
        self::assertStringContainsString('Gratis', $errors['file'][0]);
        self::assertStringContainsString('6 Fotos', $errors['file'][0]);
        self::assertCount(self::FREE_PHOTOS, $this->photoRows($venue));
    }

    public function test_a_pro_location_takes_the_seventh_photo(): void
    {
        $venue = $this->managedVenue(KnownTiers::PRO);
        $this->fillGallery($venue, self::FREE_PHOTOS);

        $this->upload(self::url($venue), $this->photoFile(), token: $this->csrfToken());

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertCount(self::FREE_PHOTOS + 1, $this->photoRows($venue));
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
        $crawler = $this->client->request('GET', '/konto/profil');

        return (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
    }

    private function managedVenue(string $tier = KnownTiers::FREE): Venue
    {
        $venue = VenueFactory::new()->withoutLocation()->create(['tier' => $tier]);
        PortalVenueAccessFactory::new()->active()->create(['user' => $this->logInAsManager(), 'venue' => $venue]);

        return $venue;
    }

    private function fillGallery(Venue $venue, int $photos): void
    {
        for ($position = 1; $position <= $photos; ++$position) {
            VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $position]);
        }
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
