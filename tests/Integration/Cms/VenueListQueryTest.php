<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cms;

use App\Cms\ReadModel\SortDirection;
use App\Cms\ReadModel\VenueListFilter;
use App\Cms\ReadModel\VenueListPage;
use App\Cms\ReadModel\VenueListQuery;
use App\Cms\ReadModel\VenueListRow;
use App\Cms\ReadModel\VenueSort;
use App\Factory\AdminUserFactory;
use App\Factory\PortalUserFactory;
use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Canton;
use App\Shared\Uid\EntityId;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Integration\Search\ListingQueryTest;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\TierLabel;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

final class VenueListQueryTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private EntityManagerInterface $em;
    private Connection $connection;
    private VenueListQuery $query;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venues'));
        $this->query = new VenueListQuery($this->connection, ListingQueryTest::enabledLocale());
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_a_complete_venue_maps_every_column(): void
    {
        $venue = $this->complete('Schloss Seeblick', town: 'Zürich', tier: KnownTiers::PRO, capacityMax: 300);
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2]);
        $room = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room, 'position' => 1]);
        $cover = $this->connection->fetchOne('SELECT id FROM venue_photos WHERE venue_id = ? AND room_id IS NULL AND position = 1', [$venue->id()]);

        $row = $this->only($this->search());

        self::assertSame([$venue->number(), 'Schloss Seeblick', 'schloss-seeblick'], [$row->number, $row->name, $row->slug]);
        self::assertSame('Zürich', $row->place->townName);
        self::assertEquals(new TierLabel(KnownTiers::PRO, 'Pro', true), $row->tier);
        self::assertSame([null, 300, VenueStatus::Published, true], [$row->capacityMin, $row->capacityMax, $row->status, $row->isPublished()]);
        self::assertSame(2, $row->photoCount);
        self::assertNotNull($row->thumbnail);
        self::assertSame($cover, $row->thumbnail->photoId);
        self::assertSame('', $row->thumbnail->alt);
        self::assertSame([], $row->missing);
        self::assertFalse($row->isIncomplete());
        self::assertSame([null, null, null, null], [$row->cmsEditedAt, $row->contentKey, $row->editorName, $row->editorInitials()]);
    }

    public function test_a_venue_without_town_or_photo_is_a_row_with_its_gaps(): void
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Wiesenhof', 'capacityMax' => 80]);

        $row = $this->only($this->search());

        self::assertSame($venue->number(), $row->number);
        self::assertEquals(new TierLabel(KnownTiers::FREE, 'Free', false), $row->tier, 'a new venue is on Free');
        self::assertFalse($row->place->isKnown());
        self::assertNull($row->slug);
        self::assertSame(0, $row->photoCount);
        self::assertNull($row->thumbnail);
        self::assertSame([VenueListRow::MISSING_PHOTO, VenueListRow::MISSING_INQUIRY_EMAIL, VenueListRow::MISSING_SUMMARY, VenueListRow::MISSING_MAIN_BUILDING], $row->missing);
    }

    public function test_each_gap_alone_makes_a_venue_incomplete(): void
    {
        $noPhoto = $this->complete('Ohne Foto');
        $this->connection->executeStatement('DELETE FROM venue_photos WHERE venue_id = ?', [$noPhoto->id()]);
        $noEmail = $this->complete('Ohne Mail');
        $this->connection->executeStatement('UPDATE venues SET inquiry_email = NULL WHERE id = ?', [$noEmail->id()]);
        $blankSummary = $this->complete('Ohne Text');
        $this->connection->executeStatement("UPDATE venue_translations SET summary = '  ' WHERE venue_id = ?", [$blankSummary->id()]);
        $noMain = $this->complete('Ohne Hauptgebäude');
        $this->connection->executeStatement('UPDATE venue_terms SET is_main = FALSE WHERE venue_id = ?', [$noMain->id()]);
        $roomPhotoOnly = $this->complete('Nur Raumfoto');
        $this->connection->executeStatement('DELETE FROM venue_photos WHERE venue_id = ?', [$roomPhotoOnly->id()]);
        VenuePhotoFactory::createOne(['venue' => $roomPhotoOnly, 'room' => RoomFactory::createOne(['venue' => $roomPhotoOnly, 'position' => 1]), 'position' => 1]);
        $this->complete('Vollständig');

        $missing = [];
        foreach ($this->search(new VenueListFilter(status: VenueListFilter::STATUS_INCOMPLETE))->rows as $row) {
            $missing[$row->name] = $row->missing;
        }

        self::assertSame([
            'Nur Raumfoto' => [VenueListRow::MISSING_PHOTO],
            'Ohne Foto' => [VenueListRow::MISSING_PHOTO],
            'Ohne Hauptgebäude' => [VenueListRow::MISSING_MAIN_BUILDING],
            'Ohne Mail' => [VenueListRow::MISSING_INQUIRY_EMAIL],
            'Ohne Text' => [VenueListRow::MISSING_SUMMARY],
        ], $missing);
    }

    public function test_a_room_photo_is_no_gallery_photo(): void
    {
        $venue = DetailVenues::full($this->em);
        $this->connection->executeStatement('DELETE FROM venue_photos WHERE venue_id = ? AND room_id IS NULL', [$venue->id()]);

        $row = $this->only($this->search());

        self::assertSame(0, $row->photoCount);
        self::assertNull($row->thumbnail);
        self::assertContains(VenueListRow::MISSING_PHOTO, $row->missing);
    }

    public function test_the_status_filters_and_counts(): void
    {
        $this->complete('Online Komplett');
        $this->complete('Online Lückenhaft', email: null);
        $this->complete('Offline Komplett', status: VenueStatus::Unpublished);
        $this->complete('Offline Lückenhaft', status: VenueStatus::Unpublished, email: null);
        $this->complete('Offline Zwei', status: VenueStatus::Unpublished);

        $all = $this->search();
        self::assertSame([5, 2, 3, 2], [$all->counts->all, $all->counts->published, $all->counts->unpublished, $all->counts->incomplete]);
        self::assertSame(5, $all->total);

        self::assertSame(['Online Komplett', 'Online Lückenhaft'], $this->names(new VenueListFilter(status: VenueListFilter::STATUS_PUBLISHED)));
        self::assertSame(['Offline Komplett', 'Offline Lückenhaft', 'Offline Zwei'], $this->names(new VenueListFilter(status: VenueListFilter::STATUS_UNPUBLISHED)));
        self::assertSame(['Offline Lückenhaft', 'Online Lückenhaft'], $this->names(new VenueListFilter(status: VenueListFilter::STATUS_INCOMPLETE)));

        $published = $this->search(new VenueListFilter(status: VenueListFilter::STATUS_PUBLISHED));
        self::assertSame(2, $published->total);
        self::assertEquals($all->counts, $published->counts);
    }

    public function test_counts_follow_every_filter_but_the_status(): void
    {
        $this->complete('Zürich Online', town: 'Zürich');
        $this->complete('Zürich Offline', town: 'Zürich', status: VenueStatus::Unpublished);
        $this->complete('Bern Online', town: 'Bern');

        $page = $this->search(new VenueListFilter(status: VenueListFilter::STATUS_UNPUBLISHED, canton: 'zh'));

        self::assertSame([2, 1, 1, 0], [$page->counts->all, $page->counts->published, $page->counts->unpublished, $page->counts->incomplete]);
        self::assertSame(['Zürich Offline'], array_map(static fn (VenueListRow $row): string => $row->name, $page->rows));
    }

    public function test_tier_canton_and_address_filters(): void
    {
        $this->complete('Pro Zürich', town: 'Zürich', tier: KnownTiers::PRO);
        $this->complete('Free Bern', town: 'Bern');
        $this->locationless('Nur Tessin', 'ti');
        $this->locationless('Nirgends', null);

        self::assertSame(['Pro Zürich'], $this->names(new VenueListFilter(tier: KnownTiers::PRO)));
        self::assertSame(['Free Bern', 'Nirgends', 'Nur Tessin'], $this->names(new VenueListFilter(tier: KnownTiers::FREE)));
        self::assertSame([], $this->names(new VenueListFilter(tier: 'gold')));
        self::assertSame(['Free Bern'], $this->names(new VenueListFilter(canton: 'be')));
        self::assertSame(['Nur Tessin'], $this->names(new VenueListFilter(canton: 'ti')));
        self::assertSame(['Nirgends'], $this->names(new VenueListFilter(canton: VenueListFilter::CANTON_NONE)));
        self::assertSame(['Nirgends', 'Nur Tessin'], $this->names(new VenueListFilter(withoutAddress: true)));
        self::assertSame(['Nirgends'], $this->names(new VenueListFilter(canton: VenueListFilter::CANTON_NONE, withoutAddress: true)));
        self::assertSame([], $this->names(new VenueListFilter(tier: KnownTiers::PRO, withoutAddress: true)));
    }

    public function test_search_by_name_town_number_and_zip(): void
    {
        $lake = $this->complete('Zürichsee Lodge', town: 'Bern');
        $this->complete('Altstadthaus', town: 'Zürich');
        $this->complete('Gurten Pavillon', town: 'Bern');
        $this->locationless('Grotto Ticino', 'ti');

        self::assertSame(['Altstadthaus', 'Zürichsee Lodge'], $this->names(new VenueListFilter(q: 'zuerich')));
        self::assertSame(['Altstadthaus', 'Zürichsee Lodge'], $this->names(new VenueListFilter(q: 'Zürich')));
        self::assertSame(['Altstadthaus', 'Zürichsee Lodge'], $this->names(new VenueListFilter(q: 'zurich')));
        self::assertSame(['Grotto Ticino'], $this->names(new VenueListFilter(q: '  grotto ')));
        self::assertSame(['Gurten Pavillon', 'Zürichsee Lodge'], $this->names(new VenueListFilter(q: '300')));
        self::assertSame(['Altstadthaus'], $this->names(new VenueListFilter(q: '8001')));
        // The number may also be a prefix of a ZIP of the other venues (the sequence is shared by every test).
        self::assertContains('Zürichsee Lodge', $this->names(new VenueListFilter(q: (string) $lake->number())));
        self::assertSame([], $this->names(new VenueListFilter(q: '123456789012')));
        self::assertSame([], $this->names(new VenueListFilter(q: '!!!')));
        self::assertCount(4, $this->names(new VenueListFilter(q: '   ')));
    }

    public function test_each_sort_in_both_directions(): void
    {
        // A third plan between Free and Pro: a tier is a row, so the sort needs no code for it (F12).
        $this->connection->executeStatement("INSERT INTO tiers (id, code, name, price_rappen, rank_weight, photos_max, rooms_max, videos_max, documents_max, contact_visible, last_minute_allowed, detailed_report, event_types_max) VALUES (gen_random_uuid(), 'plus', 'Plus', 9900, 5, 20, 5, 2, 2, TRUE, FALSE, FALSE, 6)");
        $a = $this->complete('Alpha', tier: KnownTiers::FREE, capacityMax: 200);
        $b = $this->complete('Beta', tier: KnownTiers::PRO, capacityMax: 50);
        $c = $this->complete('Gamma', tier: 'plus', capacityMax: 120);
        foreach ([[$a, '3 days'], [$b, '1 day'], [$c, '2 days']] as [$venue, $age]) {
            $this->connection->executeStatement("UPDATE venues SET updated_at = now() - INTERVAL '{$age}' WHERE id = ?", [$venue->id()]);
        }

        $expected = [
            [VenueSort::Name, ['Alpha', 'Beta', 'Gamma']],
            [VenueSort::Tier, ['Beta', 'Gamma', 'Alpha']],
            [VenueSort::Capacity, ['Beta', 'Gamma', 'Alpha']],
            [VenueSort::Updated, ['Alpha', 'Gamma', 'Beta']],
        ];
        foreach ($expected as [$sort, $names]) {
            self::assertSame($names, $this->names(sort: $sort), $sort->value.' asc');
            self::assertSame(array_reverse($names), $this->names(sort: $sort, direction: SortDirection::Desc), $sort->value.' desc');
        }
    }

    public function test_ties_break_on_the_number(): void
    {
        $first = $this->complete('Gleich');
        $second = $this->complete('Gleich');

        $numbers = array_map(static fn (VenueListRow $row): int => $row->number, $this->search(sort: VenueSort::Name, direction: SortDirection::Desc)->rows);

        self::assertSame([$first->number(), $second->number()], $numbers);
    }

    public function test_paging_and_the_clamp(): void
    {
        VenueFactory::createMany(VenueListQuery::PER_PAGE + 3);

        $first = $this->search();
        self::assertSame([VenueListQuery::PER_PAGE + 3, 1, 2, VenueListQuery::PER_PAGE], [$first->total, $first->page, $first->lastPage, $first->perPage]);
        self::assertCount(VenueListQuery::PER_PAGE, $first->rows);

        $second = $this->search(page: 2);
        self::assertCount(3, $second->rows);
        self::assertSame([], array_intersect(array_map(static fn (VenueListRow $row): int => $row->number, $first->rows), array_map(static fn (VenueListRow $row): int => $row->number, $second->rows)));

        self::assertSame(2, $this->search(page: \PHP_INT_MAX)->page);
        self::assertSame(1, $this->search(page: -5)->page);
        $empty = $this->search(new VenueListFilter(q: 'gibt es nicht'), page: 4);
        self::assertSame([0, 1, 1, []], [$empty->total, $empty->page, $empty->lastPage, $empty->rows]);
    }

    public function test_a_manager_shows_as_the_editor_by_name(): void
    {
        $venue = $this->complete('Bearbeitet');
        $this->connection->executeStatement('UPDATE venues SET cms_edited_at = now() WHERE id = ?', [$venue->id()]);
        $this->connection->insert('admin_audit_log', [
            'id' => EntityId::generate(),
            'actor_type' => 'portal_user',
            'portal_user_id' => PortalUserFactory::createOne(['firstName' => 'Nora', 'lastName' => 'Keller'])->id(),
            'command' => 'App\Venues\Command\ChangeVenueBasics',
            'subject_type' => 'venue',
            'subject_id' => (string) $venue->number(),
            'changes' => '{}',
            'created_at' => '2026-10-01 10:00:00+02',
        ]);

        self::assertSame('Nora Keller', $this->only($this->search())->editorName);
    }

    public function test_the_editor_is_the_newest_audit_row(): void
    {
        $venue = $this->complete('Bearbeitet');
        $this->connection->executeStatement('UPDATE venues SET cms_edited_at = now() WHERE id = ?', [$venue->id()]);
        $this->audit($venue, AdminUserFactory::createOne(['displayName' => 'Bruno Meier'])->id(), '2026-10-01 10:00:00+02');
        $this->audit($venue, AdminUserFactory::createOne(['displayName' => 'Andrea von Keller'])->id(), '2026-10-02 10:00:00+02');

        $row = $this->only($this->search());

        self::assertSame('Andrea von Keller', $row->editorName);
        self::assertSame('AK', $row->editorInitials());
        self::assertNotNull($row->cmsEditedAt);
    }

    public function test_a_one_word_name_gives_one_initial(): void
    {
        $venue = $this->complete('Bearbeitet');
        $this->audit($venue, AdminUserFactory::createOne(['displayName' => 'Émilie'])->id(), '2026-10-01 10:00:00+02');

        self::assertSame('É', $this->only($this->search())->editorInitials());
    }

    public function test_incomplete_gives_the_first_rows_and_the_total(): void
    {
        $this->complete('Komplett');
        foreach (['Delta', 'Alpha', 'Charlie', 'Bravo'] as $name) {
            $this->complete($name, email: null);
        }

        $list = $this->query->incomplete(3);

        self::assertSame(4, $list->total);
        self::assertSame(['Alpha', 'Bravo', 'Charlie'], array_map(static fn (VenueListRow $row): string => $row->name, $list->rows));
        self::assertSame([VenueListRow::MISSING_INQUIRY_EMAIL], $list->rows[0]->missing);
    }

    public function test_incomplete_without_any(): void
    {
        $this->complete('Komplett');

        $list = $this->query->incomplete(5);

        self::assertSame([0, []], [$list->total, $list->rows]);
    }

    public function test_recently_edited_lists_cms_edits_newest_first(): void
    {
        $old = $this->complete('Früher');
        $new = $this->complete('Neulich');
        $this->complete('Nie');
        $this->connection->executeStatement("UPDATE venues SET cms_edited_at = now() - INTERVAL '2 days' WHERE id = ?", [$old->id()]);
        $this->connection->executeStatement("UPDATE venues SET cms_edited_at = now() - INTERVAL '1 hour' WHERE id = ?", [$new->id()]);

        self::assertSame(['Neulich', 'Früher'], array_map(static fn (VenueListRow $row): string => $row->name, $this->query->recentlyEdited(5)));
        self::assertSame(['Neulich'], array_map(static fn (VenueListRow $row): string => $row->name, $this->query->recentlyEdited(1)));
    }

    public function test_quick_search_includes_unpublished_venues(): void
    {
        $this->complete('Seehaus Offline', status: VenueStatus::Unpublished);
        $this->complete('Seehaus Online');
        $this->complete('Bergwerk');

        self::assertSame(['Seehaus Offline', 'Seehaus Online'], array_map(static fn (VenueListRow $row): string => $row->name, $this->query->quickSearch('seehaus', 5)));
        self::assertCount(1, $this->query->quickSearch('seehaus', 1));
        self::assertSame([], $this->query->quickSearch('  ', 5));
    }

    public function test_by_numbers_gives_those_venues_by_name_with_their_gaps(): void
    {
        $zebra = $this->complete('Zebrahof', status: VenueStatus::Unpublished);
        $anker = $this->complete('Ankerhof', email: null);
        $this->complete('Mittelhof');

        $rows = $this->query->byNumbers([$zebra->number(), $anker->number()]);

        self::assertSame(['Ankerhof', 'Zebrahof'], array_map(static fn (VenueListRow $row): string => $row->name, $rows));
        self::assertSame([[VenueListRow::MISSING_INQUIRY_EMAIL], []], array_map(static fn (VenueListRow $row): array => $row->missing, $rows));
    }

    public function test_by_numbers_without_numbers_runs_no_statement(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAllAssociative');

        self::assertSame([], (new VenueListQuery($connection, ListingQueryTest::enabledLocale()))->byNumbers([]));
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new VenueListFilter(status: 'deleted');
    }

    private function search(?VenueListFilter $filter = null, VenueSort $sort = VenueSort::DEFAULT, SortDirection $direction = SortDirection::Asc, int $page = 1): VenueListPage
    {
        $this->em->flush();

        return $this->query->search($filter ?? new VenueListFilter(), $sort, $direction, $page);
    }

    /**
     * @return list<string>
     */
    private function names(?VenueListFilter $filter = null, VenueSort $sort = VenueSort::DEFAULT, SortDirection $direction = SortDirection::Asc): array
    {
        return array_map(static fn (VenueListRow $row): string => $row->name, $this->search($filter, $sort, $direction)->rows);
    }

    private function only(VenueListPage $page): VenueListRow
    {
        self::assertCount(1, $page->rows);

        return $page->rows[0];
    }

    /**
     * A venue with nothing missing: a gallery photo, an inquiry address, a summary and a main building.
     */
    private function complete(string $name, ?string $town = null, string $tier = KnownTiers::FREE, int $capacityMax = 120, VenueStatus $status = VenueStatus::Published, ?string $email = 'info@example.ch'): Venue
    {
        $venue = VenueFactory::createOne([
            'name' => $name,
            'tier' => $tier,
            'status' => $status,
            'capacityMax' => $capacityMax,
            'town' => DetailVenues::town($town ?? 'Bern'),
        ]);
        $venue->changeContact(null, $email, null, null);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => self::slug($name, $venue), 'summary' => 'Ein Ort für die Hochzeit.']);
        VenueTermFactory::new()->term(TermGroup::Building, 'farm_barn')->main()->create(['venue' => $venue]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $this->em->flush();

        return $venue;
    }

    private function locationless(string $name, ?string $cantonCode): Venue
    {
        $canton = null === $cantonCode ? null : repository(Canton::class)->find($cantonCode);

        return VenueFactory::new()->withoutLocation($canton)->create(['name' => $name]);
    }

    private static function slug(string $name, Venue $venue): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'é' => 'e']))), '-');

        return 'schloss-seeblick' === $slug ? $slug : $slug.'-'.$venue->number();
    }

    private function audit(Venue $venue, string $actorId, string $at): void
    {
        $this->connection->insert('admin_audit_log', [
            'id' => EntityId::generate(),
            'actor_type' => 'admin_user',
            'actor_id' => $actorId,
            'command' => 'App\Venues\Command\ChangeVenueBasics',
            'subject_type' => 'venue',
            'subject_id' => (string) $venue->number(),
            'changes' => '{}',
            'created_at' => $at,
        ]);
    }
}
