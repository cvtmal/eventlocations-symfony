<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Tests\Support\Cms\ResetsContentBlocks;
use App\Venues\PublicPhotoPolicy;
use App\Venues\RightsPolicy;
use App\Venues\ValueObject\RightsPolicyText;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;

/**
 * The folder import and the rights grants (G1): the folders are the owner's own content, so every
 * photo the import adds is granted as `system` / `import` / `staff_attestation`, a photo the import
 * removes ends its grant with the import as the revoker and keeps the grant as evidence, and a run
 * that changes nothing writes no grant.
 */
final class ImportContentGrantsTest extends KernelTestCase
{
    use ResetsContentBlocks;
    use RunsContentImport {
        setUp as private setUpImport;
        tearDown as private tearDownImport;
    }

    private const string KEY = 'schloss-beispiel';

    protected function setUp(): void
    {
        $this->setUpImport();
        $this->resetContentBlocks();
    }

    protected function tearDown(): void
    {
        $this->resetContentBlocks();
        $this->tearDownImport();
    }

    public function test_every_photo_the_import_adds_gets_a_live_grant_of_the_system(): void
    {
        $this->venueWithMedia();

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        $photos = $this->photos();
        self::assertCount(4, $photos, 'three in the gallery, one in the room');
        $grants = $this->grantsByPhoto();
        self::assertSame(array_column($photos, 'id'), array_keys($grants), 'one grant per photo, none more');
        $policy = $this->policy();
        foreach ($photos as $photo) {
            $grant = $grants[$photo['id']];
            self::assertSame($photo['original_path'], $grant['original_path']);
            self::assertSame($photo['venue_id'], $grant['venue_id']);
            self::assertSame(
                ['system', 'import', 'staff_attestation', $policy->version, true, true, false, null, null, null],
                [$grant['grantor_type'], $grant['grantor_ref'], $grant['evidence'], $grant['policy_version'], $grant['scope_listing'], $grant['scope_resized_copies'], $grant['scope_derivatives'], $grant['revoked_at'], $grant['revoked_by_type'], $grant['revoked_by_ref']],
            );
        }
        self::assertSame($policy->markdown, $this->connection->fetchOne('SELECT body_markdown FROM rights_policy_versions WHERE version = ?', [$policy->version]));
        self::assertSame(array_column($photos, 'id'), (new PublicPhotoPolicy($this->connection))->filter(array_column($photos, 'id')), 'every imported photo is public');
    }

    public function test_the_import_writes_no_grant_for_a_venue_without_photos_or_in_a_dry_run(): void
    {
        $policies = $this->rows('rights_policy_versions');
        $this->venueWithMedia();
        self::assertSame(Command::SUCCESS, $this->import(['--dry-run' => true]), $this->display());
        self::assertSame(0, $this->rows('media_rights_grants'));
        self::assertSame(0, $this->rows('venue_photos'));

        $this->content->venue('ohne-fotos');
        $this->content->remove(self::KEY);
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertSame(1, $this->rows('venues WHERE content_key IS NOT NULL'));
        self::assertSame(0, $this->rows('media_rights_grants'));
        self::assertSame($policies, $this->rows('rights_policy_versions'), 'no grant, no new policy version');
    }

    public function test_running_the_import_twice_writes_no_new_grant(): void
    {
        $this->venueWithMedia();
        $this->import();
        $before = $this->snapshot();
        self::assertCount(4, $before['grants']);

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertSame($before, $this->snapshot());
    }

    public function test_reordering_and_renaming_photos_touches_no_grant(): void
    {
        $this->venueWithMedia();
        $this->import();
        $before = $this->snapshot();

        rename($this->content->folder(self::KEY).'/photos/a.jpg', $this->content->folder(self::KEY).'/photos/z.jpg');
        $this->content->edit(self::KEY, static function (array $data): array {
            \assert(\is_array($data['photos']));
            [$a, $b, $c] = $data['photos'];
            \assert(\is_array($a));
            $a['file'] = 'z.jpg';
            $data['photos'] = [$c, $a, $b];

            return $data;
        });
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertStringContainsString('updated    schloss-beispiel', $this->display());
        self::assertSame($before['grants'], $this->snapshot()['grants']);
    }

    public function test_a_photo_the_reimport_removes_loses_its_grant_but_the_evidence_stays(): void
    {
        $this->venueWithMedia();
        $this->import();
        [, , $third] = $this->photos();
        $before = $this->grantsByPhoto()[$third['id']];

        unlink($this->content->folder(self::KEY).'/photos/c.jpg');
        $this->content->edit(self::KEY, static function (array $data): array {
            \assert(\is_array($data['photos']));
            $data['photos'] = \array_slice($data['photos'], 0, 2);

            return $data;
        });
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertStringContainsString('Photos: +0 −1.', $this->display());
        self::assertSame(0, $this->rows('venue_photos WHERE id = ?', [$third['id']]));
        $grant = $this->connection->fetchAssociative('SELECT * FROM media_rights_grants WHERE id = ?', [$before['id']]);
        self::assertIsArray($grant);
        self::assertNull($grant['venue_photo_id'], 'the link goes with the photo');
        self::assertNotNull($grant['revoked_at']);
        self::assertSame(['system', 'import'], [$grant['revoked_by_type'], $grant['revoked_by_ref']]);
        self::assertSame($third['original_path'], $grant['original_path'], 'the file it was for is still named');
        self::assertSame([$before['grantor_type'], $before['grantor_ref'], $before['evidence'], $before['policy_version']], [$grant['grantor_type'], $grant['grantor_ref'], $grant['evidence'], $grant['policy_version']], 'who granted it, and under which words, is not rewritten');
        self::assertSame(array_column($this->photos(), 'id'), array_keys($this->grantsByPhoto()), 'the three photos that are left keep their live grants');
        self::assertCount(3, $this->photos());
        self::assertSame(4, $this->rows('media_rights_grants'), 'nothing was deleted, nothing was added');
        self::assertSame(1, $this->rows('media_rights_grants WHERE revoked_at IS NOT NULL'));
    }

    public function test_a_changed_photo_is_a_new_row_with_a_new_grant_and_the_old_one_ends(): void
    {
        $this->venueWithMedia();
        $this->import();
        [, $second] = $this->photos();
        $oldGrant = $this->grantsByPhoto()[$second['id']]['id'];

        $this->content->file(self::KEY, 'photos/b.jpg', self::image(20));
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertStringContainsString('Photos: +1 −1.', $this->display());
        self::assertSame(5, $this->rows('media_rights_grants'));
        $ended = $this->connection->fetchAssociative('SELECT venue_photo_id, revoked_by_type, revoked_by_ref, revoked_at IS NOT NULL AS revoked FROM media_rights_grants WHERE id = ?', [$oldGrant]);
        self::assertSame([null, 'system', 'import', true], array_values($ended ?: []));
        $now = $this->photos();
        self::assertCount(4, $now);
        self::assertNotContains($second['id'], array_column($now, 'id'));
        self::assertSame(array_column($now, 'id'), array_keys($this->grantsByPhoto()), 'every photo that exists has exactly one live grant');
    }

    public function test_removing_a_room_ends_the_grants_of_its_photos(): void
    {
        $this->venueWithMedia();
        $this->import();
        $roomPhoto = $this->connection->fetchOne('SELECT id FROM venue_photos WHERE room_id IS NOT NULL');
        self::assertIsString($roomPhoto);

        $this->content->remove(self::KEY);
        $this->content->venue(self::KEY, [], 'Schloss Beispiel');
        $this->content->photo(self::KEY, 'a.jpg', bytes: self::image(1))->photo(self::KEY, 'b.jpg', bytes: self::image(2))->photo(self::KEY, 'c.jpg', bytes: self::image(3));
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertSame(0, $this->rows('rooms'));
        self::assertSame(0, $this->rows('venue_photos WHERE id = ?', [$roomPhoto]));
        self::assertSame(3, $this->rows('media_rights_grants WHERE revoked_at IS NULL'));
        self::assertSame(1, $this->rows("media_rights_grants WHERE revoked_at IS NOT NULL AND venue_photo_id IS NULL AND revoked_by_type = 'system' AND revoked_by_ref = 'import'"));
    }

    public function test_a_folder_that_fails_leaves_no_grant_and_a_failed_update_keeps_the_old_ones(): void
    {
        $this->connection->executeStatement("ALTER TABLE rooms ADD CONSTRAINT chk_test_no_ballroom CHECK (content_key <> 'ballroom')");
        $ballroom = ['rooms' => [['key' => 'ballroom', 'translations' => ['de' => ['name' => 'Ballsaal']]]]];
        $this->content->venue('neu', $ballroom)->photo('neu', 'a.jpg', bytes: self::image(5));
        $this->content->venue('alt')->photo('alt', 'a.jpg', bytes: self::image(6))->photo('alt', 'b.jpg', bytes: self::image(7));
        self::assertSame(Command::FAILURE, $this->import(), $this->display());
        self::assertSame(2, $this->rows('venue_photos'), 'only the folder that worked');
        $grants = $this->snapshot()['grants'];
        self::assertCount(2, $grants, 'a rolled-back folder leaves no grant behind');

        // The venue that exists loses a photo and gains the failing room in one run.
        unlink($this->content->folder('alt').'/photos/b.jpg');
        $this->content->edit('alt', static function (array $data) use ($ballroom): array {
            \assert(\is_array($data['photos']));
            $data['photos'] = \array_slice($data['photos'], 0, 1);

            return $data + $ballroom;
        });
        $this->content->remove('neu');
        self::assertSame(Command::FAILURE, $this->import(), $this->display());

        self::assertSame($grants, $this->snapshot()['grants'], 'the revocation of the removed photo was rolled back with the rest');
        self::assertSame(2, $this->rows('venue_photos'));
    }

    private function venueWithMedia(): void
    {
        $this->content->venue(self::KEY, ['rooms' => [['key' => 'rittersaal', 'translations' => ['de' => ['name' => 'Rittersaal']]]]], 'Schloss Beispiel');
        $this->content->photo(self::KEY, 'a.jpg', bytes: self::image(1));
        $this->content->photo(self::KEY, 'b.jpg', bytes: self::image(2));
        $this->content->photo(self::KEY, 'c.jpg', bytes: self::image(3));
        $this->content->photo(self::KEY, 'saal.jpg', 'rittersaal', self::image(4));
    }

    private function policy(): RightsPolicyText
    {
        $policy = self::getContainer()->get(RightsPolicy::class);
        self::assertInstanceOf(RightsPolicy::class, $policy);

        return $policy->current();
    }

    /**
     * @return list<array{id: string, venue_id: string, position: int, original_path: string}> the gallery by position, then the rooms
     */
    private function photos(): array
    {
        /** @var list<array{id: string, venue_id: string, position: int, original_path: string}> $photos */
        $photos = $this->connection->fetchAllAssociative('SELECT id, venue_id, position, original_path FROM venue_photos ORDER BY room_id NULLS FIRST, position');

        return $photos;
    }

    /**
     * The live grants by the id of their photo, in the order of `photos()`.
     *
     * @return array<string, array{id: string, venue_id: string, original_path: string, grantor_type: string, grantor_ref: string, evidence: string, policy_version: string, scope_listing: bool, scope_resized_copies: bool, scope_derivatives: bool, revoked_at: ?string, revoked_by_type: ?string, revoked_by_ref: ?string}>
     */
    private function grantsByPhoto(): array
    {
        /** @var list<array{venue_photo_id: string, id: string, venue_id: string, original_path: string, grantor_type: string, grantor_ref: string, evidence: string, policy_version: string, scope_listing: bool, scope_resized_copies: bool, scope_derivatives: bool, revoked_at: ?string, revoked_by_type: ?string, revoked_by_ref: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT g.venue_photo_id, g.id, g.venue_id, g.original_path, g.grantor_type, g.grantor_ref, g.evidence, g.policy_version,
                    g.scope_listing, g.scope_resized_copies, g.scope_derivatives, g.revoked_at, g.revoked_by_type, g.revoked_by_ref
                FROM media_rights_grants g
                JOIN venue_photos p ON p.id = g.venue_photo_id
                WHERE g.revoked_at IS NULL
                ORDER BY p.room_id NULLS FIRST, p.position
                SQL,
        );
        $byPhoto = [];
        foreach ($rows as $row) {
            $photoId = $row['venue_photo_id'];
            unset($row['venue_photo_id']);
            $byPhoto[$photoId] = $row;
        }

        return $byPhoto;
    }

    /**
     * @return array{grants: list<array<string, mixed>>, policies: list<array<string, mixed>>}
     */
    private function snapshot(): array
    {
        return [
            'grants' => $this->connection->fetchAllAssociative('SELECT * FROM media_rights_grants ORDER BY id'),
            'policies' => $this->connection->fetchAllAssociative('SELECT * FROM rights_policy_versions ORDER BY version'),
        ];
    }
}
