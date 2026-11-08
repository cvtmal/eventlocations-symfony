<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Factory\RoomFactory;
use App\Factory\RoomTranslationFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Tests\Support\Admin\AdminSession;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The room editor's JSON endpoints (#25, Step 7.4): add, change, order, remove; each answers the full
 * room list (PF10), field errors by dotted path.
 */
final class RoomApiTest extends WebTestCase
{
    use AdminSession {
        tearDown as private endAdminSession;
    }
    use Factories;
    use SendsVenueMediaRequests;

    protected function tearDown(): void
    {
        $this->removeVenueMedia();
        $this->endAdminSession();
    }

    public function test_a_room_is_added_and_the_room_facts_follow(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();

        $this->json('POST', self::url($venue), self::body(), $this->csrfToken());

        self::assertResponseStatusCodeSame(201);
        $answer = $this->answer();
        $rooms = $answer['rooms'];
        self::assertIsArray($rooms);
        self::assertCount(1, $rooms);
        self::assertIsArray($rooms[0]);
        self::assertSame($answer['room'], $rooms[0]['id']);
        self::assertSame([['de' => 'Rittersaal'], 180, '4.20', 'parquet', ['min' => 40, 'max' => 100]], [$rooms[0]['names'], $rooms[0]['areaM2'], $rooms[0]['heightM'], $rooms[0]['floorMaterial'], $rooms[0]['seating']['banquet'] ?? null]);
        self::assertSame(
            ['room_count' => 1, 'largest_room_m2' => 180],
            $this->connection->fetchAssociative('SELECT room_count, largest_room_m2 FROM venues WHERE id = ?', [$venue->id()]),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'no name' => [['names' => ['de' => '']], 'names.de'];
        yield 'min above max without layouts' => [['capacityMin' => 500, 'seating' => []], 'capacityMax'];
        yield 'a height of 31 m' => [['heightM' => '31'], 'heightM'];
        yield 'an unknown floor' => [['floorMaterial' => 'marble'], 'floorMaterial'];
        yield 'seating min above max' => [['seating' => ['banquet' => ['min' => 120, 'max' => 100]]], 'seating.banquet.max'];
        yield 'an unknown key' => [['id' => 'x'], ''];
        yield 'another locale' => [['names' => ['de' => 'Saal', 'fr' => 'Salle']], 'names'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBodies')]
    public function test_an_invalid_room_is_a_422_on_its_field(array $override, string $field): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();

        $this->json('POST', self::url($venue), array_replace(self::body(), $override), $this->csrfToken());

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey($field, $this->errors());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM rooms WHERE venue_id = ?', [$venue->id()]));
    }

    /**
     * F12: Free holds one room. The second is refused with a message that names the plan (the whole form, so the
     * key is empty), and nothing is written; the same room on Pro is taken.
     */
    public function test_a_second_room_is_refused_on_free_and_taken_on_pro(): void
    {
        $this->logInAs(AdminRole::Editor);
        $free = self::venue();
        RoomFactory::createOne(['venue' => $free, 'position' => 1]);
        $token = $this->csrfToken();

        $this->json('POST', self::url($free), self::body(), $token);

        self::assertResponseStatusCodeSame(422);
        $errors = $this->errors();
        self::assertSame([''], array_keys($errors));
        self::assertStringContainsString('Gratis', $errors[''][0]);
        self::assertStringContainsString('1 Raum', $errors[''][0]);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM rooms WHERE venue_id = ?', [$free->id()]));

        $pro = self::venue(KnownTiers::PRO);
        RoomFactory::createOne(['venue' => $pro, 'position' => 1]);

        $this->json('POST', self::url($pro), self::body(), $token);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM rooms WHERE venue_id = ?', [$pro->id()]));
    }

    public function test_malformed_json_is_a_422(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->json('POST', self::url(self::venue()), '{nope', $this->csrfToken());

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('names.de', $this->errors());
    }

    public function test_a_change_uses_the_room_token(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $token = $this->csrfToken();
        $this->json('POST', self::url($venue), self::body(), $token);
        $rooms = $this->answer()['rooms'];
        self::assertIsArray($rooms);
        self::assertIsArray($rooms[0]);
        [$id, $loadedAt] = [(string) $rooms[0]['id'], (string) $rooms[0]['token']];

        $this->json('PUT', self::url($venue).'/'.$id, ['loadedAt' => $loadedAt, 'areaM2' => 240] + self::body(), $token);
        self::assertResponseIsSuccessful();
        self::assertSame(240, (int) $this->connection->fetchOne('SELECT largest_room_m2 FROM venues WHERE id = ?', [$venue->id()]));

        $this->json('PUT', self::url($venue).'/'.$id, ['loadedAt' => '1'] + self::body(), $token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame([''], array_keys($this->errors()));

        $this->json('PUT', self::url($venue).'/'.$id, self::body(), $token);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('loadedAt', $this->errors());
    }

    public function test_rooms_are_reordered_and_a_foreign_list_is_a_409(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $first = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        $second = RoomFactory::createOne(['venue' => $venue, 'position' => 2]);
        $token = $this->csrfToken();

        $this->json('PUT', self::url($venue).'/order', ['ids' => [$second->id(), $first->id()]], $token);
        self::assertResponseIsSuccessful();
        $rooms = $this->answer()['rooms'];
        self::assertIsArray($rooms);
        self::assertSame([$second->id(), $first->id()], array_map(static fn (mixed $room): string => \is_array($room) ? (string) $room['id'] : '', $rooms));

        $this->json('PUT', self::url($venue).'/order', ['ids' => [$second->id()]], $token);
        self::assertResponseStatusCodeSame(409);

        $this->json('PUT', self::url($venue).'/order', ['ids' => [1, 2]], $token);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('ids', $this->errors());
    }

    public function test_a_removed_room_leaves_its_photos_in_the_gallery(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $room = RoomFactory::createOne(['venue' => $venue]);
        RoomTranslationFactory::createOne(['room' => $room]);
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room, 'position' => 1]);

        $this->json('DELETE', self::url($venue).'/'.$room->id(), token: $this->csrfToken());

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->answer()['rooms']);
        self::assertNull($this->connection->fetchOne('SELECT room_id FROM venue_photos WHERE id = ?', [$photo->id()]));
    }

    public function test_another_venues_room_and_an_unknown_venue_are_404(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $foreign = RoomFactory::createOne([]);
        $token = $this->csrfToken();

        $this->json('DELETE', self::url($venue).'/'.$foreign->id(), token: $token);
        self::assertResponseStatusCodeSame(404);
        $this->json('PUT', self::url($venue).'/'.$foreign->id(), ['loadedAt' => '1'] + self::body(), $token);
        self::assertResponseStatusCodeSame(404);
        $this->json('POST', '/admin/api/cms/venues/999999999/rooms', self::body(), $token);
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM rooms WHERE id = ?', [$foreign->id()]));
    }

    public function test_without_the_csrf_header_a_write_is_refused_and_anonymous_is_401(): void
    {
        $venue = self::venue();
        $this->json('POST', self::url($venue), self::body(), 'any');
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $this->logInAs(AdminRole::Editor);
        $this->json('POST', self::url($venue), self::body());
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM rooms WHERE venue_id = ?', [$venue->id()]));
    }

    private static function venue(string $tier = KnownTiers::FREE): Venue
    {
        return VenueFactory::new()->withoutLocation()->create(['tier' => $tier]);
    }

    private static function url(Venue $venue): string
    {
        return '/admin/api/cms/venues/'.$venue->number().'/rooms';
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(): array
    {
        return [
            'names' => ['de' => 'Rittersaal'],
            'descriptions' => ['de' => null],
            'capacityMin' => 20,
            'capacityMax' => 120,
            'areaM2' => 180,
            'heightM' => '4.20',
            'floorMaterial' => 'parquet',
            'seating' => ['banquet' => ['min' => 40, 'max' => 100]],
        ];
    }
}
