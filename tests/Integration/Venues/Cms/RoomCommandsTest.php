<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues\Cms;

use App\Factory\RoomFactory;
use App\Factory\RoomTranslationFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Venues\Command\AddRoom;
use App\Venues\Command\ChangeRoom;
use App\Venues\Command\RemoveRoom;
use App\Venues\Command\ReorderRooms;
use App\Venues\Exception\StaleEdit;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\Seating;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * AddRoom, ChangeRoom, ReorderRooms, RemoveRoom (#25, Phase 4, CMS-5, D11, D14, D19, D23, PF11).
 */
final class RoomCommandsTest extends KernelTestCase
{
    use Factories;
    use HandlesVenueMedia;

    private const array NO_SEATING = [];

    public function test_add_room_writes_the_row_its_texts_and_seating_and_recomputes_the_facts(): void
    {
        // Pro: the venue holds a room already, and Free holds one (F12).
        $venue = VenueFactory::createOne(['tier' => KnownTiers::PRO]);
        RoomFactory::createOne(['venue' => $venue, 'position' => 1, 'areaM2' => 120, 'heightM' => '3.50']);
        $this->em->clear();

        $roomId = $this->dispatch(new AddRoom(
            $venue->number(),
            ['de' => '  Rittersaal '],
            ['de' => " Mit Kamin.\n"],
            20,
            150,
            240,
            '6.2',
            'parquet',
            ['banquet' => ['min' => 40, 'max' => 120], 'apero' => ['min' => null, 'max' => 150]],
        ));
        self::assertIsString($roomId);

        $row = $this->connection->fetchAssociative('SELECT * FROM rooms WHERE id = ?', [$roomId]);
        self::assertIsArray($row);
        self::assertSame(2, $row['position']);
        self::assertNull($row['content_key']);
        self::assertSame([40, 150, 240, '6.20', 'parquet'], [$row['capacity_min'], $row['capacity_max'], $row['area_m2'], $row['height_m'], $row['floor_material']]);
        self::assertSame([40, 120, null, 150, null, null, null, null], [
            $row['capacity_banquet_min'], $row['capacity_banquet_max'], $row['capacity_apero_min'], $row['capacity_apero_max'],
            $row['capacity_seminar_min'], $row['capacity_seminar_max'], $row['capacity_concert_min'], $row['capacity_concert_max'],
        ]);
        self::assertSame(
            [['locale' => 'de', 'name' => 'Rittersaal', 'description' => 'Mit Kamin.']],
            $this->connection->fetchAllAssociative('SELECT locale, name, description FROM room_translations WHERE room_id = ?', [$roomId]),
        );
        self::assertSame([2, 240, '6.20'], $this->facts($venue->id()));
        self::assertTrue($this->cmsEdited($venue->number()));
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(1, $this->purger()->listings);
    }

    /**
     * F12: the plan's `rooms_max` is checked before anything is written; the message names the plan and the limit.
     */
    public function test_a_second_room_is_refused_on_free_and_writes_nothing(): void
    {
        $venue = VenueFactory::createOne(['tier' => KnownTiers::FREE]);
        RoomFactory::createOne(['venue' => $venue, 'position' => 1, 'areaM2' => 120, 'heightM' => '3.50']);
        $this->em->clear();

        $violation = $this->violation(new AddRoom($venue->number(), ['de' => 'Zweiter Saal'], [], null, 80, 90, '3.00', null, self::NO_SEATING));

        self::assertSame('rooms.error.too_many', $violation->translationKey());
        self::assertNull($violation->field);
        self::assertSame(['max' => 1, 'tier' => 'Free'], $violation->parameters);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM rooms WHERE venue_id = ?', [$venue->id()]));
        self::assertFalse($this->cmsEdited($venue->number()));
        self::assertSame([], $this->purger()->venues);
    }

    public function test_the_first_room_of_a_free_venue_is_taken(): void
    {
        $venue = VenueFactory::createOne(['tier' => KnownTiers::FREE]);
        $this->em->clear();

        $roomId = $this->dispatch(new AddRoom($venue->number(), ['de' => 'Einziger Saal'], [], null, 80, 90, '3.00', null, self::NO_SEATING));

        self::assertIsString($roomId);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM rooms WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_the_room_limit_is_read_from_the_plan_row_at_the_time_of_the_save(): void
    {
        $venue = VenueFactory::createOne(['tier' => KnownTiers::PRO]);
        RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        RoomFactory::createOne(['venue' => $venue, 'position' => 2]);
        $this->em->clear();
        $this->connection->executeStatement("UPDATE tiers SET rooms_max = 2 WHERE code = 'pro'");

        $violation = $this->violation(new AddRoom($venue->number(), ['de' => 'Dritter Saal'], [], null, null, null, null, null, self::NO_SEATING));

        self::assertSame(['max' => 2, 'tier' => 'Pro'], $violation->parameters);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM rooms WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_a_room_needs_a_name_in_the_default_locale_and_only_enabled_locales(): void
    {
        $venue = VenueFactory::createOne();
        $this->em->clear();

        foreach ([[], ['de' => '  ']] as $names) {
            $violation = $this->violation(new AddRoom($venue->number(), $names, [], null, null, null, null, null, self::NO_SEATING));
            self::assertSame('rooms.error.name_required', $violation->translationKey());
            self::assertSame('names.de', $violation->field);
        }
        self::assertInstanceOf(\InvalidArgumentException::class, $this->failure(new AddRoom($venue->number(), ['de' => 'Saal', 'xx' => 'Hall'], [], null, null, null, null, null, self::NO_SEATING)));
        self::assertInstanceOf(\InvalidArgumentException::class, $this->failure(new AddRoom($venue->number(), ['de' => 'Saal'], [], null, null, null, null, null, ['dance' => ['min' => 1, 'max' => 2]])));
        self::assertInstanceOf(\InvalidArgumentException::class, $this->failure(new AddRoom($venue->number(), ['de' => 'Saal'], [], null, null, null, null, 'marble', self::NO_SEATING)));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM rooms WHERE venue_id = ?', [$venue->id()]));
        self::assertFalse($this->cmsEdited($venue->number()));
    }

    public function test_change_room_replaces_the_fields_keeps_the_content_key_and_recomputes_the_facts(): void
    {
        $venue = VenueFactory::createOne();
        $room = RoomFactory::createOne(['venue' => $venue, 'areaM2' => 100, 'heightM' => '3.50', 'contentKey' => 'saal']);
        $this->connection->executeStatement('UPDATE rooms SET capacity_min = 10, capacity_max = 30, capacity_seminar_min = 10, capacity_seminar_max = 30 WHERE id = ?', [$room->id()]);
        RoomTranslationFactory::createOne(['room' => $room, 'locale' => 'de', 'name' => 'Saal', 'description' => 'Alt']);
        $this->ageRow('rooms', $room->id());
        $this->em->clear();

        $this->dispatch(new ChangeRoom(
            $venue->number(), $room->id(), $this->rowToken('rooms', $room->id()),
            ['de' => 'Grosser Saal'], ['de' => ''], null, 200, 310, '4.75', null,
            ['concert' => ['min' => 50, 'max' => 250]],
        ));

        $row = $this->connection->fetchAssociative('SELECT * FROM rooms WHERE id = ?', [$room->id()]);
        self::assertIsArray($row);
        self::assertSame(['saal', 310, '4.75', null, null, 50, 250], [$row['content_key'], $row['area_m2'], $row['height_m'], $row['capacity_seminar_min'], $row['capacity_seminar_max'], $row['capacity_concert_min'], $row['capacity_concert_max']]);
        self::assertSame([50, 250], [$row['capacity_min'], $row['capacity_max']], 'the layouts give the range, not the stated 200');
        self::assertSame(
            [['name' => 'Grosser Saal', 'description' => null]],
            $this->connection->fetchAllAssociative('SELECT name, description FROM room_translations WHERE room_id = ?', [$room->id()]),
        );
        self::assertSame([1, 310, '4.75'], $this->facts($venue->id()));
        self::assertSame(1, $this->purger()->listings);
    }

    public function test_an_unchanged_save_changes_nothing_and_a_text_change_alone_moves_the_token(): void
    {
        $venue = VenueFactory::createOne();
        $room = RoomFactory::createOne(['venue' => $venue, 'capacityMin' => null, 'capacityMax' => 80, 'areaM2' => 100, 'heightM' => '3.50', 'floorMaterial' => null]);
        RoomTranslationFactory::createOne(['room' => $room, 'locale' => 'de', 'name' => 'Saal', 'description' => null]);
        $this->ageRow('rooms', $room->id());
        $this->em->clear();
        $token = $this->rowToken('rooms', $room->id());

        $this->dispatch(new ChangeRoom($venue->number(), $room->id(), $token, ['de' => 'Saal '], [], null, 80, 100, '3.5', null, self::NO_SEATING));
        self::assertSame($token, $this->rowToken('rooms', $room->id()));
        self::assertFalse($this->cmsEdited($venue->number()));
        self::assertSame([], $this->purger()->venues);

        $this->em->clear();
        $this->dispatch(new ChangeRoom($venue->number(), $room->id(), $token, ['de' => 'Saal'], ['de' => 'Neu mit Bühne.'], null, 80, 100, '3.50', null, self::NO_SEATING));
        self::assertNotSame($token, $this->rowToken('rooms', $room->id()));
        self::assertTrue($this->cmsEdited($venue->number()));
    }

    public function test_the_layouts_give_the_room_its_range_and_without_them_the_stated_one_returns(): void
    {
        $venue = VenueFactory::createOne();
        $room = RoomFactory::new()->withSeating(Seating::Block, 12, 40)->withSeating(Seating::UShape, null, 24)->create(['venue' => $venue, 'capacityMax' => 500, 'areaM2' => 100, 'heightM' => null, 'floorMaterial' => null]);
        RoomTranslationFactory::createOne(['room' => $room, 'locale' => 'de', 'name' => 'Saal', 'description' => null]);
        self::assertSame([12, 40], $this->capacity($room->id()));
        $this->ageRow('rooms', $room->id());
        $this->em->clear();
        $token = $this->rowToken('rooms', $room->id());
        $layouts = ['block' => ['min' => 12, 'max' => 40], 'u_shape' => ['min' => null, 'max' => 24]];

        $this->dispatch(new ChangeRoom($venue->number(), $room->id(), $token, ['de' => 'Saal'], [], 1, 999, 100, null, null, $layouts));
        self::assertSame($token, $this->rowToken('rooms', $room->id()), 'a stated range under layouts is no change');

        $this->em->clear();
        $this->dispatch(new ChangeRoom($venue->number(), $room->id(), $token, ['de' => 'Saal'], [], 1, 999, 100, null, null, self::NO_SEATING));
        self::assertSame([1, 999], $this->capacity($room->id()));
        self::assertNotSame($token, $this->rowToken('rooms', $room->id()));
    }

    public function test_a_stale_token_and_a_foreign_room_are_refused(): void
    {
        $venue = VenueFactory::createOne();
        $room = RoomFactory::createOne(['venue' => $venue]);
        RoomTranslationFactory::createOne(['room' => $room, 'locale' => 'de', 'name' => 'Saal']);
        $foreign = RoomFactory::createOne()->id();
        $this->ageRow('rooms', $room->id());
        $this->em->clear();
        $stale = (string) ((int) $this->rowToken('rooms', $room->id()) - 1);

        self::assertInstanceOf(StaleEdit::class, $this->failure(new ChangeRoom($venue->number(), $room->id(), $stale, ['de' => 'Neu'], [], null, null, null, null, null, self::NO_SEATING)));
        self::assertInstanceOf(VenueRowNotFound::class, $this->failure(new ChangeRoom($venue->number(), $foreign, '1', ['de' => 'Neu'], [], null, null, null, null, null, self::NO_SEATING)));
        self::assertInstanceOf(VenueRowNotFound::class, $this->failure(new RemoveRoom($venue->number(), $foreign)));
        self::assertInstanceOf(VenueRuleViolation::class, $this->failure(new ChangeRoom($venue->number(), $room->id(), $this->rowToken('rooms', $room->id()), ['de' => ''], [], null, null, null, null, null, self::NO_SEATING)));
        self::assertSame('Saal', $this->connection->fetchOne('SELECT name FROM room_translations WHERE room_id = ?', [$room->id()]));
    }

    public function test_reorder_rooms_persists_a_contiguous_order_and_purges_no_listing(): void
    {
        $venue = VenueFactory::createOne();
        $a = RoomFactory::createOne(['venue' => $venue, 'position' => 1])->id();
        $b = RoomFactory::createOne(['venue' => $venue, 'position' => 2])->id();
        $c = RoomFactory::createOne(['venue' => $venue, 'position' => 3])->id();
        $foreign = RoomFactory::createOne()->id();
        $this->em->clear();

        $this->dispatch(new ReorderRooms($venue->number(), [$c, $a, $b]));
        self::assertSame([$c, $a, $b], $this->rooms($venue->id()));
        self::assertSame([1, 2, 3], $this->roomPositions($venue->id()));
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(0, $this->purger()->listings);

        $this->em->clear();
        self::assertInstanceOf(StaleEdit::class, $this->failure(new ReorderRooms($venue->number(), [$c, $a, $foreign])));
        self::assertInstanceOf(StaleEdit::class, $this->failure(new ReorderRooms($venue->number(), [$c, $a])));
        self::assertSame([$c, $a, $b], $this->rooms($venue->id()));
    }

    public function test_remove_room_moves_its_photos_to_the_gallery_end_and_recomputes_the_facts(): void
    {
        $venue = VenueFactory::createOne();
        $hall = RoomFactory::createOne(['venue' => $venue, 'position' => 1, 'areaM2' => 300, 'heightM' => '8.00']);
        $garden = RoomFactory::createOne(['venue' => $venue, 'position' => 2, 'areaM2' => 90, 'heightM' => null]);
        $cover = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1])->id();
        $second = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $hall, 'position' => 2])->id();
        $first = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $hall, 'position' => 1])->id();
        $this->em->clear();

        $this->dispatch(new RemoveRoom($venue->number(), $hall->id()));

        self::assertSame([$cover, $first, $second], $this->scope($venue->id()));
        self::assertSame([1, 2, 3], $this->positions($venue->id()));
        self::assertSame([$garden->id()], $this->rooms($venue->id()));
        self::assertSame([1], $this->roomPositions($venue->id()));
        self::assertSame([1, 90, null], $this->facts($venue->id()));
        self::assertSame(1, $this->purger()->listings);

        $this->em->clear();
        $this->dispatch(new RemoveRoom($venue->number(), $garden->id()));
        self::assertSame([0, null, null], $this->facts($venue->id()));
    }

    /**
     * @return list<string>
     */
    private function rooms(string $venueId): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn('SELECT id FROM rooms WHERE venue_id = ? ORDER BY position', [$venueId]);

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function roomPositions(string $venueId): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn('SELECT position FROM rooms WHERE venue_id = ? ORDER BY position', [$venueId]));
    }

    /**
     * @return array{mixed, mixed}
     */
    private function capacity(string $roomId): array
    {
        $row = $this->connection->fetchAssociative('SELECT capacity_min, capacity_max FROM rooms WHERE id = ?', [$roomId]);
        self::assertIsArray($row);

        return [$row['capacity_min'], $row['capacity_max']];
    }

    /**
     * @return array{mixed, mixed, mixed} room_count, largest_room_m2, max_room_height_m
     */
    private function facts(string $venueId): array
    {
        $row = $this->connection->fetchAssociative('SELECT room_count, largest_room_m2, max_room_height_m FROM venues WHERE id = ?', [$venueId]);
        self::assertIsArray($row);

        return [$row['room_count'], $row['largest_room_m2'], $row['max_room_height_m']];
    }
}
