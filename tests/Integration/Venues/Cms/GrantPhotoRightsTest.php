<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues\Cms;

use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Integration\Search\ListingQueryTest;
use App\Tests\Support\Cms\ResetsContentBlocks;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Command\GrantPhotoRights;
use App\Venues\EntitlementResolver;
use App\Venues\Entity\Venue;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\ReadModel\VenueSitemapQuery;
use App\Venues\RightsPolicy;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use App\Venues\ValueObject\PhotoGrant;
use App\Venues\ValueObject\RightsPolicyText;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

/**
 * GrantPhotoRights (G1): the photos of a venue that have no live grant get one, all or the named
 * ones, under the declaration text in force, in one transaction that purges the venue and the
 * listings once. Photos are real uploads without a grant, so they start private.
 */
final class GrantPhotoRightsTest extends KernelTestCase
{
    use Factories;
    use HandlesVenueMedia {
        setUp as private setUpMedia;
        tearDown as private tearDownMedia;
    }
    use ImportsTownDataset;
    use ResetsContentBlocks;

    private const string MANAGER = '019a0000-0000-7000-8000-0000000000aa';
    private const string ADMIN = '019a0000-0000-7000-8000-0000000000ad';

    protected function setUp(): void
    {
        $this->setUpMedia();
        $this->resetContentBlocks();
    }

    protected function tearDown(): void
    {
        $this->resetContentBlocks();
        $this->tearDownMedia();
    }

    public function test_it_grants_every_photo_without_a_grant_in_the_gallery_and_in_the_rooms(): void
    {
        $venue = $this->venue();
        $number = $venue->number();
        $room = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        $roomId = $room->id();
        $gallery = [$this->addPhoto($number, $this->upload())->photoId, $this->addPhoto($number, $this->upload())->photoId];
        $inRoom = $this->addPhoto($number, $this->upload(), $roomId)->photoId;
        $policy = $this->policy()->current();

        $granted = $this->dispatch(new GrantPhotoRights($number, Grantor::manager(self::MANAGER), GrantEvidence::Declaration, derivatives: true));

        self::assertSame(3, $granted);
        $grants = $this->grants($venue->id());
        self::assertSame([...$gallery, $inRoom], array_keys($grants), 'one live grant per photo');
        foreach ($grants as $photoId => $grant) {
            self::assertSame(
                ['manager', self::MANAGER, 'declaration', $policy->version, true, true, true, null, null, null],
                [$grant['grantor_type'], $grant['grantor_ref'], $grant['evidence'], $grant['policy_version'], $grant['scope_listing'], $grant['scope_resized_copies'], $grant['scope_derivatives'], $grant['revoked_at'], $grant['revoked_by_type'], $grant['revoked_by_ref']],
                $photoId,
            );
            self::assertSame($this->connection->fetchOne('SELECT original_path FROM venue_photos WHERE id = ?', [$photoId]), $grant['original_path']);
        }
        self::assertSame(
            [$policy->version, $policy->markdown],
            array_values($this->connection->fetchAssociative('SELECT version, body_markdown FROM rights_policy_versions WHERE version = ?', [$policy->version]) ?: []),
            'the words the grantor saw can be read back',
        );
    }

    public function test_the_derivatives_are_off_unless_the_grantor_chooses_them(): void
    {
        $venue = $this->venue();
        $this->addPhoto($venue->number(), $this->upload());

        $this->dispatch(new GrantPhotoRights($venue->number(), Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation));

        $row = array_values($this->grants($venue->id()))[0];
        self::assertSame(['staff', self::ADMIN, 'staff_attestation', false], [$row['grantor_type'], $row['grantor_ref'], $row['evidence'], $row['scope_derivatives']]);
        self::assertTrue($row['scope_listing'] && $row['scope_resized_copies'], 'listing and resized copies are what the site does with every photo');
    }

    public function test_it_grants_only_the_named_photos(): void
    {
        $venue = $this->venue();
        $number = $venue->number();
        $photos = [$this->addPhoto($number, $this->upload())->photoId, $this->addPhoto($number, $this->upload())->photoId, $this->addPhoto($number, $this->upload())->photoId];

        $granted = $this->dispatch(new GrantPhotoRights($number, Grantor::manager(self::MANAGER), GrantEvidence::Declaration, photoIds: [$photos[2], $photos[0]]));

        self::assertSame(2, $granted);
        self::assertSame([$photos[0], $photos[2]], array_keys($this->grants($venue->id())));
        self::assertSame(1, $this->dispatch(new GrantPhotoRights($number, Grantor::manager(self::MANAGER), GrantEvidence::Declaration)), 'the third is still waiting for a grant');
        self::assertCount(3, $this->grants($venue->id()));
    }

    public function test_a_photo_named_twice_is_granted_once(): void
    {
        $venue = $this->venue();
        $photo = $this->addPhoto($venue->number(), $this->upload())->photoId;

        $granted = $this->dispatch(new GrantPhotoRights($venue->number(), Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation, photoIds: [$photo, $photo]));

        self::assertSame(1, $granted);
        self::assertSame([$photo], array_keys($this->grants($venue->id())));
    }

    public function test_naming_no_photo_grants_nothing_and_purges_nothing(): void
    {
        $venue = $this->venue();
        $this->addPhoto($venue->number(), $this->upload());
        $this->resetPurger();

        $granted = $this->dispatch(new GrantPhotoRights($venue->number(), Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation, photoIds: []));

        self::assertSame(0, $granted);
        self::assertSame([], $this->grants($venue->id()));
        self::assertSame([[], 0], [$this->purger()->venues, $this->purger()->listings]);
    }

    public function test_a_photo_of_another_venue_is_not_found_and_nothing_else_is_granted(): void
    {
        $venue = $this->venue();
        $own = $this->addPhoto($venue->number(), $this->upload())->photoId;
        $foreign = VenuePhotoFactory::new()->withoutGrant()->create()->id();
        $this->em->clear();
        $this->resetPurger();

        $failure = $this->failure(new GrantPhotoRights($venue->number(), Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation, photoIds: [$own, $foreign]));

        self::assertInstanceOf(VenueRowNotFound::class, $failure);
        self::assertSame(['photo', $foreign], [$failure->kind, $failure->rowId]);
        self::assertSame([], $this->grants($venue->id()), 'the valid id beside it was not granted');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM media_rights_grants WHERE venue_photo_id = ?', [$foreign]));
        self::assertSame([[], 0], [$this->purger()->venues, $this->purger()->listings]);
    }

    public function test_an_id_that_is_no_uuid_is_not_found(): void
    {
        $venue = $this->venue();
        $this->addPhoto($venue->number(), $this->upload());

        $failure = $this->failure(new GrantPhotoRights($venue->number(), Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation, photoIds: ["1'; DROP TABLE venue_photos; --"]));

        self::assertInstanceOf(VenueRowNotFound::class, $failure);
        self::assertSame([], $this->grants($venue->id()));
    }

    public function test_an_unknown_venue_is_not_found(): void
    {
        self::assertInstanceOf(VenueNotFound::class, $this->failure(new GrantPhotoRights(999_999_999, Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation)));
    }

    public function test_a_photo_that_already_has_a_grant_is_left_alone(): void
    {
        $venue = $this->venue();
        $number = $venue->number();
        $granted = $this->addPhoto($number, $this->upload(), grant: new PhotoGrant(Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation))->photoId;
        $waiting = $this->addPhoto($number, $this->upload())->photoId;
        $this->connection->executeStatement("UPDATE media_rights_grants SET created_at = created_at - INTERVAL '1 day', updated_at = updated_at - INTERVAL '1 day' WHERE venue_photo_id = ?", [$granted]);
        $before = $this->grants($venue->id());

        $count = $this->dispatch(new GrantPhotoRights($number, Grantor::manager(self::MANAGER), GrantEvidence::Declaration, derivatives: true));

        self::assertSame(1, $count);
        $after = $this->grants($venue->id());
        self::assertSame($before[$granted], $after[$granted], 'the first grant keeps its grantor, evidence, scope and time');
        self::assertSame(['staff', 'staff_attestation', false], [$after[$granted]['grantor_type'], $after[$granted]['evidence'], $after[$granted]['scope_derivatives']]);
        self::assertSame(['manager', 'declaration', true], [$after[$waiting]['grantor_type'], $after[$waiting]['evidence'], $after[$waiting]['scope_derivatives']]);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_the_second_run_grants_nothing_and_writes_nothing(): void
    {
        $venue = $this->venue();
        $this->addPhoto($venue->number(), $this->upload());
        $this->addPhoto($venue->number(), $this->upload());
        $command = new GrantPhotoRights($venue->number(), Grantor::manager(self::MANAGER), GrantEvidence::Declaration);
        self::assertSame(2, $this->dispatch($command));
        $this->ageVenue($venue);
        $grants = $this->connection->fetchAllAssociative('SELECT * FROM media_rights_grants WHERE venue_id = ? ORDER BY id', [$venue->id()]);
        $policies = $this->connection->fetchAllAssociative('SELECT * FROM rights_policy_versions ORDER BY version');
        $venueRow = $this->connection->fetchAssociative('SELECT updated_at, cms_edited_at FROM venues WHERE id = ?', [$venue->id()]);
        $this->resetPurger();

        self::assertSame(0, $this->dispatch($command));

        self::assertSame($grants, $this->connection->fetchAllAssociative('SELECT * FROM media_rights_grants WHERE venue_id = ? ORDER BY id', [$venue->id()]));
        self::assertSame($policies, $this->connection->fetchAllAssociative('SELECT * FROM rights_policy_versions ORDER BY version'));
        self::assertSame($venueRow, $this->connection->fetchAssociative('SELECT updated_at, cms_edited_at FROM venues WHERE id = ?', [$venue->id()]));
        self::assertSame([[], 0], [$this->purger()->venues, $this->purger()->listings], 'nothing became public, so nothing is purged');
    }

    public function test_it_purges_the_venue_and_the_listings_once_when_it_grants(): void
    {
        $venue = $this->venue();
        $this->addPhoto($venue->number(), $this->upload());
        $this->addPhoto($venue->number(), $this->upload());
        $this->resetPurger();

        $this->dispatch(new GrantPhotoRights($venue->number(), Grantor::manager(self::MANAGER), GrantEvidence::Declaration));

        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(1, $this->purger()->listings);
    }

    public function test_it_does_not_freeze_the_venue_against_the_import(): void
    {
        $venue = $this->venue();
        $this->addPhoto($venue->number(), $this->upload());
        self::assertTrue($this->cmsEdited($venue->number()), 'an upload is a CMS edit');
        $this->connection->executeStatement('UPDATE venues SET cms_edited_at = NULL WHERE id = ?', [$venue->id()]);

        self::assertSame(1, $this->dispatch(new GrantPhotoRights($venue->number(), Grantor::manager(self::MANAGER), GrantEvidence::Declaration)));

        self::assertFalse($this->cmsEdited($venue->number()), 'a grant changes no content the folder import would overwrite');
    }

    public function test_granting_moves_the_sitemap_lastmod_because_the_photos_become_public(): void
    {
        $venue = $this->venue();
        $this->addPhoto($venue->number(), $this->upload());
        $locale = ListingQueryTest::enabledLocale();
        $sitemap = new VenueSitemapQuery($this->connection, $locale, new EntitlementResolver($this->connection), new MockClock('2026-10-30 10:00:00', 'UTC'));
        foreach (['venues' => 'id', 'venue_translations' => 'venue_id', 'venue_photos' => 'venue_id'] as $table => $column) {
            $this->connection->executeStatement(\sprintf("UPDATE %s SET updated_at = '2026-01-01 10:00:00+00' WHERE %s = ?", $table, $column), [$venue->id()]);
        }
        self::assertSame('2026-01-01T10:00:00+00:00', $sitemap->page(1)[0]->lastmod->format('c'));

        $this->dispatch(new GrantPhotoRights($venue->number(), Grantor::manager(self::MANAGER), GrantEvidence::Declaration));

        self::assertGreaterThan(new \DateTimeImmutable('2026-06-01'), $sitemap->page(1)[0]->lastmod, 'the page has a new image from now on, and Google trusts lastmod only when it is accurate');
    }

    public function test_it_is_audited_on_the_venue_with_the_choice_and_no_ids(): void
    {
        $venue = $this->venue();
        $number = $venue->number();
        $photo = $this->addPhoto($number, $this->upload())->photoId;
        $this->addPhoto($number, $this->upload());

        $this->dispatch(new GrantPhotoRights($number, Grantor::manager(self::MANAGER), GrantEvidence::Declaration, derivatives: true));
        $this->dispatch(new GrantPhotoRights($number, Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation, photoIds: [$photo]));

        $rows = array_values(array_filter($this->auditRows($number), static fn (array $row): bool => str_ends_with($row['command'], 'GrantPhotoRights')));
        self::assertCount(2, $rows, 'one row per dispatch, also for the one that granted nothing');
        // jsonb does not keep the order of the keys.
        self::assertEquals(['grantor' => 'manager', 'evidence' => 'declaration', 'derivatives' => true, 'photos' => 'all'], $rows[0]['changes']);
        self::assertEquals(['grantor' => 'staff', 'evidence' => 'staff_attestation', 'derivatives' => false, 'photos' => 1], $rows[1]['changes']);
        self::assertStringNotContainsString($photo, json_encode($rows, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::MANAGER, json_encode($rows, \JSON_THROW_ON_ERROR), 'the actor is the audit row\'s own column, the summary holds no id');
    }

    public function test_an_edit_of_the_declaration_text_makes_a_new_version_for_later_grants_only(): void
    {
        $venue = $this->venue();
        $number = $venue->number();
        $first = $this->addPhoto($number, $this->upload())->photoId;
        $second = $this->addPhoto($number, $this->upload())->photoId;
        $original = $this->policy()->current();
        $this->dispatch(new GrantPhotoRights($number, Grantor::manager(self::MANAGER), GrantEvidence::Declaration, photoIds: [$first]));

        $edited = "**Bildrechte-Erklärung**\n\nNeuer Wortlaut.";
        $this->connection->executeStatement("INSERT INTO content_blocks (key, locale, body_markdown, updated_at) VALUES ('rights.declaration', 'de', ?, CURRENT_TIMESTAMP)", [$edited."\n"]);
        $this->resetContentBlocks();
        $this->dispatch(new GrantPhotoRights($number, Grantor::manager(self::MANAGER), GrantEvidence::Declaration, photoIds: [$second]));

        $grants = $this->grants($venue->id());
        $next = RightsPolicyText::of($edited);
        self::assertNotSame($original->version, $next->version);
        self::assertSame($original->version, $grants[$first]['policy_version'], 'the first grantor agreed to the old words, which stay on record');
        self::assertSame($next->version, $grants[$second]['policy_version']);
        self::assertSame($original->markdown, $this->connection->fetchOne('SELECT body_markdown FROM rights_policy_versions WHERE version = ?', [$original->version]));
        self::assertSame($edited, $this->connection->fetchOne('SELECT body_markdown FROM rights_policy_versions WHERE version = ?', [$next->version]));
    }

    private function venue(): Venue
    {
        $venue = VenueFactory::createOne(['name' => 'Seehaus Freigabe', 'town' => DetailVenues::town('Zürich')]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => 'seehaus-freigabe']);

        return $venue;
    }

    private function policy(): RightsPolicy
    {
        $policy = self::getContainer()->get(RightsPolicy::class);
        self::assertInstanceOf(RightsPolicy::class, $policy);

        return $policy;
    }

    private function resetPurger(): void
    {
        $this->purger()->venues = [];
        $this->purger()->listings = 0;
    }

    /**
     * The live grants of the venue by photo id, in photo order (gallery by position, then rooms).
     *
     * @return array<string, array{original_path: string, grantor_type: string, grantor_ref: string, evidence: string, policy_version: string, scope_listing: bool, scope_resized_copies: bool, scope_derivatives: bool, revoked_at: ?string, revoked_by_type: ?string, revoked_by_ref: ?string, created_at: string, updated_at: string}>
     */
    private function grants(string $venueId): array
    {
        /** @var list<array{venue_photo_id: string, original_path: string, grantor_type: string, grantor_ref: string, evidence: string, policy_version: string, scope_listing: bool, scope_resized_copies: bool, scope_derivatives: bool, revoked_at: ?string, revoked_by_type: ?string, revoked_by_ref: ?string, created_at: string, updated_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT g.venue_photo_id, g.original_path, g.grantor_type, g.grantor_ref, g.evidence, g.policy_version,
                    g.scope_listing, g.scope_resized_copies, g.scope_derivatives, g.revoked_at, g.revoked_by_type, g.revoked_by_ref,
                    g.created_at, g.updated_at
                FROM media_rights_grants g
                JOIN venue_photos p ON p.id = g.venue_photo_id
                WHERE g.venue_id = ? AND g.revoked_at IS NULL
                ORDER BY p.room_id NULLS FIRST, p.position
                SQL,
            [$venueId],
        );
        $byPhoto = [];
        foreach ($rows as $row) {
            $photoId = $row['venue_photo_id'];
            unset($row['venue_photo_id']);
            $byPhoto[$photoId] = $row;
        }

        return $byPhoto;
    }
}
