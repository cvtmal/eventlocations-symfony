<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Cms\VenueMediaApi;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Tests\Application\Cms\SendsVenueMediaRequests;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Tests\Support\Portal\PortalSession;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * F11: a manager edits the rooms of their own location on the Räume tab, with staff's island and bodies
 * (`VenueRoomsApi`), behind the voter and the `portal_api` token; the plan caps the rooms (F12) and every write purges
 * the venue's page.
 */
final class LocationRoomsTest extends WebTestCase
{
    use Factories;
    use PortalSession {
        tearDown as private endPortalSession;
    }
    use SendsVenueMediaRequests;

    protected function tearDown(): void
    {
        $this->removeVenueMedia();
        $this->endPortalSession();
    }

    public function test_the_tab_mounts_the_room_editor_with_the_portal_endpoints_and_the_plan(): void
    {
        $venue = $this->managedVenue();
        RoomFactory::createOne(['venue' => $venue]);

        $crawler = $this->client->request('GET', self::page($venue));

        self::assertResponseIsSuccessful();
        $props = json_decode((string) $crawler->filter('[data-island="room-editor"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);
        self::assertIsArray($props['rooms']);
        self::assertCount(1, $props['rooms']);
        self::assertSame([
            'add' => self::api($venue),
            'order' => self::api($venue).'/order',
            'change' => self::api($venue).'/'.VenueMediaApi::ID_PLACEHOLDER,
            'remove' => self::api($venue).'/'.VenueMediaApi::ID_PLACEHOLDER,
        ], $props['endpoints']);
        self::assertSame(self::page($venue), $props['reloadUrl']);
        self::assertSelectorTextContains('.tabs--record [aria-current="page"]', 'Räume');
        self::assertSelectorTextContains('aside #location-plan', 'Free');
        self::assertSelectorTextContains('aside', 'Räume 1 von 1');
    }

    public function test_a_room_is_added_changed_and_removed_and_each_write_purges_the_venue(): void
    {
        $venue = $this->managedVenue(KnownTiers::PRO);
        $token = $this->csrfToken();

        $this->json('POST', self::api($venue), self::body(), $token);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame([$venue->number()], array_values(array_unique($this->purger()->venues)));
        $answer = $this->answer();
        self::assertIsString($answer['room']);
        $roomId = $answer['room'];
        self::assertIsArray($answer['rooms']);
        self::assertIsArray($answer['rooms'][0]);
        self::assertIsString($answer['rooms'][0]['token']);

        $this->json('PUT', self::api($venue).'/'.$roomId, ['loadedAt' => $answer['rooms'][0]['token'], 'names' => ['de' => 'Gewölbekeller']] + self::body(), $token);
        self::assertResponseIsSuccessful();
        self::assertSame([$venue->number()], array_values(array_unique($this->purger()->venues)));
        self::assertSame('Gewölbekeller', $this->connection->fetchOne('SELECT name FROM room_translations WHERE room_id = ?', [$roomId]));

        $this->json('DELETE', self::api($venue).'/'.$roomId, token: $token);
        self::assertResponseIsSuccessful();
        self::assertSame([$venue->number()], array_values(array_unique($this->purger()->venues)));
        self::assertSame(0, $this->roomCount($venue));
        self::assertSame(
            ['portal_user'],
            array_values(array_unique($this->connection->fetchFirstColumn("SELECT actor_type FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $venue->number()]))),
        );
    }

    public function test_the_second_room_of_a_free_location_is_refused_with_the_plan_named(): void
    {
        $venue = $this->managedVenue();
        RoomFactory::createOne(['venue' => $venue]);

        $this->json('POST', self::api($venue), self::body(), $this->csrfToken());

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $errors = $this->errors();
        self::assertSame(['Mit dem Plan Free ist höchstens 1 Raum möglich. Entferne zuerst einen Raum; mehr gibt es mit einem grösseren Plan.'], array_merge(...array_values($errors)));
        self::assertSame(1, $this->roomCount($venue));
    }

    public function test_another_managers_location_is_a_403_and_a_missing_token_changes_nothing(): void
    {
        $manager = $this->logInAsManager();
        $other = VenueFactory::new()->withoutLocation()->create();
        PortalVenueAccessFactory::new()->active()->create(['venue' => $other]);
        $token = $this->csrfToken();

        $this->client->request('GET', self::page($other));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->json('POST', self::api($other), self::body(), $token);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(0, $this->roomCount($other));

        $own = VenueFactory::new()->withoutLocation()->create(['tier' => KnownTiers::PRO]);
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $own]);
        $this->json('POST', self::api($own), self::body());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(0, $this->roomCount($own));
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

    private function roomCount(Venue $venue): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM rooms WHERE venue_id = ?', [$venue->id()]);
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(): array
    {
        return [
            'names' => ['de' => 'Saal'],
            'descriptions' => ['de' => ''],
            'capacityMin' => null,
            'capacityMax' => 80,
            'areaM2' => 120,
            'heightM' => null,
            'floorMaterial' => null,
            'seating' => [],
        ];
    }

    private static function page(Venue $venue): string
    {
        return '/konto/locations/'.$venue->number().'/raeume';
    }

    private static function api(Venue $venue): string
    {
        return '/konto/api/locations/'.$venue->number().'/rooms';
    }
}
