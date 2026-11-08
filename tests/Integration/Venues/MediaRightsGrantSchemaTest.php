<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The constraints of `media_rights_grants` and `rights_policy_versions` (G1), proven against the real
 * schema with raw SQL, in the style of VenueSchemaMigrationTest: one rejection case per constraint,
 * the boundaries the constraints must let through, what a deleted photo or venue does to a grant,
 * and what the migration does to the photos that exist before it runs.
 */
final class MediaRightsGrantSchemaTest extends KernelTestCase
{
    private const TABLES = ['media_rights_grants', 'rights_policy_versions'];

    private const MIGRATION_FILE = 'migrations/Version20261107140523.php';

    private const IDS = [
        '{town}' => '019a0000-0000-7000-8000-000000000001',
        '{venue}' => '019a0000-0000-7000-8000-000000000101',
        '{venue2}' => '019a0000-0000-7000-8000-000000000102',
        '{room}' => '019a0000-0000-7000-8000-000000000201',
        '{photo}' => '019a0000-0000-7000-8000-000000000401',
        '{photo2}' => '019a0000-0000-7000-8000-000000000402',
        '{photo3}' => '019a0000-0000-7000-8000-000000000403',
        '{grant}' => '019a0000-0000-7000-8000-000000000501',
        '{grant2}' => '019a0000-0000-7000-8000-000000000502',
        '{grant3}' => '019a0000-0000-7000-8000-000000000503',
        '{missing}' => '019a0000-0000-7000-8000-000000000999',
    ];

    /** The NOT NULL image variant columns of venue_photos with valid values. */
    private const PHOTO_COLUMNS = [
        '{photo-cols}' => 'width, height, dominant_color, variants',
        '{photo-vals}' => '1600, 1067, \'#b9a58c\', \'{"widths": [360, 640, 960, 1280], "formats": ["avif", "webp", "jpg"]}\'',
    ];

    /** The columns a grant needs; the rest has defaults. */
    private const GRANT_COLUMNS = 'id, venue_id, venue_photo_id, original_path, grantor_type, grantor_ref, evidence, policy_version';

    /**
     * Two venues, three photos (two in the first venue), a policy version of its own, and a live
     * grant on each photo: manager/declaration, staff/staff_attestation and system/staff_attestation.
     */
    private const SEED = [
        "INSERT INTO towns (id, canton_code, zip, name, slug, bfs_number, lat, lng, population) VALUES ('{town}', 'zh', '8999', 'Testdorf Rechte', 'testdorf-rechte', 9998, 47.3, 8.5, 100)",
        "INSERT INTO venues (id, name, status, capacity_max, zip, town_id, canton_code, lat, lng) VALUES ('{venue}', 'Testhaus', 'published', 100, '8999', '{town}', 'zh', 47.3, 8.5), ('{venue2}', 'Zweithaus', 'published', 50, '8999', '{town}', 'zh', 47.3, 8.5)",
        "INSERT INTO venue_photos (id, venue_id, room_id, position, original_path, {photo-cols}) VALUES ('{photo}', '{venue}', NULL, 1, 'demo/a.jpg', {photo-vals}), ('{photo2}', '{venue}', NULL, 2, 'demo/b.jpg', {photo-vals}), ('{photo3}', '{venue2}', NULL, 1, 'demo/c.jpg', {photo-vals})",
        "INSERT INTO rights_policy_versions (version, body_markdown) VALUES ('abcdef123456', 'Der Wortlaut.')",
        "INSERT INTO media_rights_grants (id, venue_id, venue_photo_id, original_path, grantor_type, grantor_ref, evidence, policy_version) VALUES ('{grant}', '{venue}', '{photo}', 'demo/a.jpg', 'manager', 'm-1', 'declaration', 'abcdef123456'), ('{grant2}', '{venue}', '{photo2}', 'demo/b.jpg', 'staff', 's-1', 'staff_attestation', 'legacy'), ('{grant3}', '{venue2}', '{photo3}', 'demo/c.jpg', 'system', 'import', 'staff_attestation', 'legacy')",
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();

        foreach (self::SEED as $sql) {
            $this->sql($sql);
        }
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    /**
     * Statements that must fail on the named constraint. Only the last statement of a case is
     * expected to fail; the optional third element replaces the constraint name as the expected
     * part of the error message, for a column limit that is no named constraint (its first element
     * then only labels the case).
     *
     * @return iterable<string, array{0: string, 1: list<string>, 2?: string}>
     */
    public static function rejections(): iterable
    {
        $grant = static fn (string $id, string $photo, string $type = 'system', string $ref = 'x', string $evidence = 'upload', string $policy = 'legacy'): string => \sprintf(
            "INSERT INTO media_rights_grants ({grant-cols}) VALUES ('%s', '{venue}', %s, 'demo/z.jpg', '%s', '%s', '%s', '%s')",
            $id,
            'NULL' === $photo ? 'NULL' : "'".$photo."'",
            $type,
            $ref,
            $evidence,
            $policy,
        );
        $revoke = static fn (string $set): string => 'UPDATE media_rights_grants SET '.$set." WHERE id = '{grant}'";

        // rights_policy_versions
        yield 'policy version taken' => ['pk_rights_policy_versions', ["INSERT INTO rights_policy_versions (version, body_markdown) VALUES ('abcdef123456', 'Anders.')"]];
        yield 'policy version upper case' => ['chk_rights_policy_versions_version', ["INSERT INTO rights_policy_versions (version, body_markdown) VALUES ('ABCDEF123456', 'Text.')"]];
        yield 'policy version of two characters' => ['chk_rights_policy_versions_version', ["INSERT INTO rights_policy_versions (version, body_markdown) VALUES ('ab', 'Text.')"]];
        yield 'policy version with a hyphen' => ['chk_rights_policy_versions_version', ["INSERT INTO rights_policy_versions (version, body_markdown) VALUES ('legacy-2', 'Text.')"]];
        yield 'policy version of 41 characters' => ['varchar(40)', ["INSERT INTO rights_policy_versions (version, body_markdown) VALUES ('".str_repeat('a', 41)."', 'Text.')"], 'value too long'];
        yield 'policy version without a text' => ['not null', ["INSERT INTO rights_policy_versions (version) VALUES ('abcdef654321')"], 'null value in column "body_markdown"'];

        // media_rights_grants
        yield 'grant id taken' => ['pk_media_rights_grants', [$grant('{grant}', 'NULL')]];
        yield 'second live grant for a photo' => ['uk_media_rights_grants_live_photo', [$grant('{missing}', '{photo}')]];
        yield 'unknown grantor type' => ['chk_media_rights_grants_grantor_type', [$revoke("grantor_type = 'admin'")]];
        yield 'grantor type in upper case' => ['chk_media_rights_grants_grantor_type', [$revoke("grantor_type = 'Staff'")]];
        yield 'unknown evidence' => ['chk_media_rights_grants_evidence', [$revoke("evidence = 'email'")]];
        yield 'unknown revoker type' => ['chk_media_rights_grants_revoked_by_type', [$revoke("revoked_at = now(), revoked_by_type = 'robot', revoked_by_ref = 'x'")]];
        yield 'revoked at without a revoker' => ['chk_media_rights_grants_revocation', [$revoke('revoked_at = now()')]];
        yield 'revoker type without a time and a ref' => ['chk_media_rights_grants_revocation', [$revoke("revoked_by_type = 'staff'")]];
        yield 'revoker ref without a time and a type' => ['chk_media_rights_grants_revocation', [$revoke("revoked_by_ref = 'x'")]];
        yield 'revoked at and type without a ref' => ['chk_media_rights_grants_revocation', [$revoke("revoked_at = now(), revoked_by_type = 'staff'")]];
        yield 'revoked at and ref without a type' => ['chk_media_rights_grants_revocation', [$revoke("revoked_at = now(), revoked_by_ref = 'x'")]];
        yield 'revoker type and ref without a time' => ['chk_media_rights_grants_revocation', [$revoke("revoked_by_type = 'staff', revoked_by_ref = 'x'")]];
        yield 'a revocation taken back' => ['chk_media_rights_grants_revocation', [
            $revoke("revoked_at = now(), revoked_by_type = 'staff', revoked_by_ref = 'x'"),
            $revoke('revoked_at = NULL'),
        ]];
        yield 'grant of an unknown venue' => ['fk_media_rights_grants_venue_id', ["INSERT INTO media_rights_grants ({grant-cols}) VALUES ('{missing}', '{missing}', NULL, 'demo/z.jpg', 'system', 'x', 'upload', 'legacy')"]];
        yield 'grant of an unknown photo' => ['fk_media_rights_grants_venue_photo_id', [$grant('{missing}', '{missing}')]];
        yield 'grant under an unknown policy version' => ['fk_media_rights_grants_policy_version', [$grant('{missing}', 'NULL', policy: 'nonexistent1')]];
        yield 'used policy version is not deleted' => ['fk_media_rights_grants_policy_version', ["DELETE FROM rights_policy_versions WHERE version = 'abcdef123456'"]];
        yield 'legacy policy version is not deleted while grants use it' => ['fk_media_rights_grants_policy_version', ["DELETE FROM rights_policy_versions WHERE version = 'legacy'"]];
        yield 'grantor ref of 65 characters' => ['varchar(64)', [$grant('{missing}', 'NULL', ref: str_repeat('x', 65))], 'value too long'];
        yield 'grantor type of 11 characters' => ['varchar(10)', [$grant('{missing}', 'NULL', type: str_repeat('x', 11))], 'value too long'];
        yield 'grantor ref missing' => ['not null', ["INSERT INTO media_rights_grants (id, venue_id, original_path, grantor_type, evidence, policy_version) VALUES ('{missing}', '{venue}', 'demo/z.jpg', 'system', 'upload', 'legacy')"], 'null value in column "grantor_ref"'];
        yield 'original path missing' => ['not null', ["INSERT INTO media_rights_grants (id, venue_id, grantor_type, grantor_ref, evidence, policy_version) VALUES ('{missing}', '{venue}', 'system', 'x', 'upload', 'legacy')"], 'null value in column "original_path"'];
    }

    /**
     * Boundary values and combinations the constraints must let through.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function acceptances(): iterable
    {
        $live = "INSERT INTO media_rights_grants ({grant-cols}) VALUES ('{missing}', '{venue}', '{photo}', 'demo/a.jpg', 'manager', 'm-2', 'upload', 'legacy')";
        $revoked = "INSERT INTO media_rights_grants ({grant-cols}, revoked_at, revoked_by_type, revoked_by_ref) VALUES ('{missing}', '{venue}', '{photo}', 'demo/a.jpg', 'manager', 'm-2', 'upload', 'legacy', now(), 'staff', 's-9')";
        $revokeOld = "UPDATE media_rights_grants SET revoked_at = now(), revoked_by_type = 'staff', revoked_by_ref = 's-9' WHERE id = '{grant}'";

        yield 'a revoked grant beside the live one of a photo (history)' => [[$revoked]];
        yield 'a new live grant once the old one is revoked' => [[$revokeOld, $live]];
        yield 'a second revoked grant for a photo' => [[$revoked, str_replace("'{missing}'", "'019a0000-0000-7000-8000-000000000998'", $revoked)]];
        yield 'revoked by a manager or the system' => [[
            "UPDATE media_rights_grants SET revoked_at = now(), revoked_by_type = 'manager', revoked_by_ref = 'm-1' WHERE id = '{grant}'",
            "UPDATE media_rights_grants SET revoked_at = now(), revoked_by_type = 'system', revoked_by_ref = 'removal' WHERE id = '{grant2}'",
        ]];
        yield 'live grants without a photo, as many as there are' => [[
            "INSERT INTO media_rights_grants ({grant-cols}) VALUES ('{missing}', '{venue}', NULL, 'demo/gone.jpg', 'system', 'x', 'upload', 'legacy'), ('019a0000-0000-7000-8000-000000000998', '{venue}', NULL, 'demo/gone.jpg', 'system', 'x', 'upload', 'legacy')",
        ]];
        yield 'a grantor reference of 64 characters' => [["UPDATE media_rights_grants SET grantor_ref = '".str_repeat('x', 64)."' WHERE id = '{grant}'"]];
        yield 'a multi-byte grantor reference of 64 characters' => [["UPDATE media_rights_grants SET grantor_ref = '".str_repeat('ä', 64)."' WHERE id = '{grant}'"]];
        yield 'every grantor type' => [[
            "UPDATE media_rights_grants SET grantor_type = 'manager' WHERE id = '{grant2}'",
            "UPDATE media_rights_grants SET grantor_type = 'staff' WHERE id = '{grant2}'",
            "UPDATE media_rights_grants SET grantor_type = 'system' WHERE id = '{grant2}'",
        ]];
        yield 'every kind of evidence' => [[
            "UPDATE media_rights_grants SET evidence = 'declaration' WHERE id = '{grant2}'",
            "UPDATE media_rights_grants SET evidence = 'upload' WHERE id = '{grant2}'",
            "UPDATE media_rights_grants SET evidence = 'staff_attestation' WHERE id = '{grant2}'",
        ]];
        yield 'every scope switched' => [["UPDATE media_rights_grants SET scope_listing = FALSE, scope_resized_copies = FALSE, scope_derivatives = TRUE WHERE id = '{grant}'"]];
        yield 'policy version of three characters' => [["INSERT INTO rights_policy_versions (version, body_markdown) VALUES ('abc', 'Text.')"]];
        yield 'policy version of forty characters' => [["INSERT INTO rights_policy_versions (version, body_markdown) VALUES ('".str_repeat('a1', 20)."', 'Text.')"]];
        yield 'policy version of digits only' => [["INSERT INTO rights_policy_versions (version, body_markdown) VALUES ('123456789012', 'Text.')"]];
        yield 'a policy version nobody uses can go' => [["INSERT INTO rights_policy_versions (version, body_markdown) VALUES ('unused123456', 'Text.')", "DELETE FROM rights_policy_versions WHERE version = 'unused123456'"]];
    }

    /**
     * @param list<string> $statements
     */
    #[DataProvider('rejections')]
    public function test_constraint_rejects(string $constraint, array $statements, ?string $message = null): void
    {
        $last = array_pop($statements);
        self::assertNotNull($last);
        foreach ($statements as $sql) {
            $this->sql($sql);
        }

        try {
            $this->sql($last);
            self::fail(\sprintf('The statement should violate %s.', $constraint));
        } catch (DriverException $exception) {
            self::assertStringContainsString($message ?? '"'.$constraint.'"', $exception->getMessage());
        }
    }

    /**
     * @param list<string> $statements
     */
    #[DataProvider('acceptances')]
    public function test_constraints_accept(array $statements): void
    {
        foreach ($statements as $sql) {
            self::assertGreaterThan(0, $this->sql($sql), $sql);
        }
    }

    public function test_every_constraint_has_a_rejection_case(): void
    {
        $tables = "'".implode("', '", self::TABLES)."'";
        $constraints = $this->connection->fetchFirstColumn(
            "SELECT conname FROM pg_constraint WHERE conrelid::regclass::text IN ({$tables}) AND contype IN ('c', 'u', 'f', 'p')",
        );
        // The partial unique on the live grant of a photo is an index, not a constraint.
        $uniqueIndexes = $this->connection->fetchFirstColumn(
            "SELECT indexname FROM pg_indexes WHERE tablename IN ({$tables}) AND indexname LIKE 'uk\\_%'",
        );
        $names = array_values(array_unique([...$constraints, ...$uniqueIndexes]));
        $tested = array_values(array_unique(array_map(
            static fn (array $case): string => $case[0],
            array_filter(iterator_to_array(self::rejections()), static fn (array $case): bool => !isset($case[2])),
        )));

        self::assertContains('uk_media_rights_grants_live_photo', $names);
        self::assertCount(11, $names, 'two primary keys, three foreign keys, five checks and the live-photo unique');
        foreach ($names as $name) {
            self::assertContains($name, $tested, $name.' has no rejection case');
        }
        foreach ($tested as $name) {
            self::assertContains($name, $names, $name.' is not a constraint of the schema');
        }
    }

    public function test_the_live_unique_only_binds_live_grants(): void
    {
        $this->sql("UPDATE media_rights_grants SET revoked_at = now(), revoked_by_type = 'staff', revoked_by_ref = 's-9' WHERE id = '{grant}'");

        $this->sql("INSERT INTO media_rights_grants ({grant-cols}) VALUES ('{missing}', '{venue}', '{photo}', 'demo/a.jpg', 'manager', 'm-2', 'upload', 'legacy')");

        self::assertSame(2, (int) $this->connection->fetchOne("SELECT count(*) FROM media_rights_grants WHERE venue_photo_id = '".self::IDS['{photo}']."'"));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM media_rights_grants WHERE venue_photo_id = '".self::IDS['{photo}']."' AND revoked_at IS NULL"));
    }

    public function test_a_new_grant_defaults_to_the_listing_and_resized_copies_without_derivatives(): void
    {
        $grant = $this->connection->fetchAssociative("SELECT scope_listing, scope_resized_copies, scope_derivatives, revoked_at, revoked_by_type, revoked_by_ref, created_at IS NOT NULL AS has_created, updated_at IS NOT NULL AS has_updated FROM media_rights_grants WHERE id = '".self::IDS['{grant}']."'");

        self::assertSame([true, true, false, null, null, null, true, true], array_values($grant ?: []));
    }

    public function test_deleting_a_photo_keeps_its_grant_and_cuts_only_the_link(): void
    {
        $columns = 'id, venue_id, original_path, grantor_type, grantor_ref, evidence, policy_version, scope_listing, scope_resized_copies, scope_derivatives, revoked_at, revoked_by_type, revoked_by_ref, created_at, updated_at';
        $before = $this->connection->fetchAssociative("SELECT {$columns} FROM media_rights_grants WHERE id = '".self::IDS['{grant}']."'");

        $this->sql("DELETE FROM venue_photos WHERE id = '{photo}'");

        self::assertNotFalse($before);
        self::assertSame($before, $this->connection->fetchAssociative("SELECT {$columns} FROM media_rights_grants WHERE id = '".self::IDS['{grant}']."'"));
        self::assertNull($this->connection->fetchOne("SELECT venue_photo_id FROM media_rights_grants WHERE id = '".self::IDS['{grant}']."'"));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT count(*) FROM media_rights_grants'));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM venue_photos WHERE id = '".self::IDS['{photo2}']."'"), 'the other photo of the venue is untouched');
    }

    public function test_deleting_a_venue_deletes_its_grants_and_only_its_own(): void
    {
        $this->sql("DELETE FROM venues WHERE id = '{venue}'");

        self::assertSame([self::IDS['{grant3}']], $this->connection->fetchFirstColumn('SELECT id FROM media_rights_grants'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM rights_policy_versions WHERE version = ?', ['abcdef123456']), 'the policy versions are not the venue\'s');
    }

    public function test_the_migration_brings_the_legacy_policy_version(): void
    {
        $legacy = $this->connection->fetchOne("SELECT body_markdown FROM rights_policy_versions WHERE version = 'legacy'");

        self::assertIsString($legacy);
        self::assertNotSame('', trim($legacy));
    }

    /**
     * The photos of a database that exists before the migration: down() takes the tables away, the
     * photos stay, and up() gives every photo, in the gallery and in rooms, a `system` /
     * `migration` / `staff_attestation` grant under the `legacy` policy version.
     */
    public function test_the_migration_gives_every_existing_photo_a_legacy_grant(): void
    {
        $this->sql("INSERT INTO rooms (id, venue_id, position) VALUES ('{room}', '{venue}', 1)");
        $this->sql("INSERT INTO venue_photos (id, venue_id, room_id, position, original_path, {photo-cols}) VALUES (gen_random_uuid(), '{venue}', '{room}', 1, 'demo/room.jpg', {photo-vals})");
        $photos = (int) $this->connection->fetchOne('SELECT count(*) FROM venue_photos');
        self::assertGreaterThanOrEqual(4, $photos);

        $this->migrate('down');
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('public.media_rights_grants')"));
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('public.rights_policy_versions')"));
        self::assertSame($photos, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_photos'), 'down() leaves the photos');

        $this->migrate('up');

        self::assertSame(['legacy'], $this->connection->fetchFirstColumn('SELECT version FROM rights_policy_versions'));
        $grants = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT g.id, g.venue_id, g.venue_photo_id, g.original_path, g.grantor_type, g.grantor_ref, g.evidence, g.policy_version,
                    g.scope_listing, g.scope_resized_copies, g.scope_derivatives, g.revoked_at, g.revoked_by_type, g.revoked_by_ref,
                    p.venue_id AS photo_venue, p.original_path AS photo_path
                FROM media_rights_grants g
                LEFT JOIN venue_photos p ON p.id = g.venue_photo_id
                SQL,
        );
        self::assertCount($photos, $grants, 'one grant per photo, none more');
        self::assertCount($photos, array_unique(array_column($grants, 'venue_photo_id')), 'every photo has its own');
        self::assertCount($photos, array_unique(array_column($grants, 'id')));
        foreach ($grants as $grant) {
            self::assertSame($grant['photo_venue'], $grant['venue_id']);
            self::assertSame($grant['photo_path'], $grant['original_path']);
            self::assertSame(['system', 'migration', 'staff_attestation', 'legacy', true, true, false, null, null, null], [
                $grant['grantor_type'], $grant['grantor_ref'], $grant['evidence'], $grant['policy_version'],
                $grant['scope_listing'], $grant['scope_resized_copies'], $grant['scope_derivatives'],
                $grant['revoked_at'], $grant['revoked_by_type'], $grant['revoked_by_ref'],
            ]);
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $grant['id'], 'a UUID v7');
        }
    }

    public function test_the_migration_on_a_database_without_photos_makes_no_grant(): void
    {
        $this->sql('DELETE FROM venue_photos');
        $this->migrate('down');

        $this->migrate('up');

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM media_rights_grants'));
        self::assertSame(['legacy'], $this->connection->fetchFirstColumn('SELECT version FROM rights_policy_versions'));
    }

    private function sql(string $sql): int
    {
        return (int) $this->connection->executeStatement(strtr($sql, [...self::IDS, ...self::PHOTO_COLUMNS, '{grant-cols}' => self::GRANT_COLUMNS]));
    }

    /**
     * Runs the migration of G1 in the test's transaction (Postgres DDL is transactional, so the
     * rollback restores the schema). Migrations are loaded by path, not autoloaded.
     */
    private function migrate(string $direction): void
    {
        $file = \dirname(__DIR__, 3).'/'.self::MIGRATION_FILE;
        require_once $file;
        $class = 'DoctrineMigrations\\'.basename($file, '.php');
        $migration = new $class($this->connection, new NullLogger());
        self::assertInstanceOf(AbstractMigration::class, $migration);
        'up' === $direction ? $migration->up(new Schema()) : $migration->down(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }
}
