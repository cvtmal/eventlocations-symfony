<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\PublicPhotoPolicy;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The part of PublicPhotoPolicy (G1) that needs no database: the shape of the predicate every public
 * read model splices into its WHERE, the check that the alias is a plain SQL identifier, and which ids
 * `filter()` passes on to the database at all.
 * What the predicate does to real rows: tests/Integration/Venues/PublicPhotoPolicyTest.
 */
final class PublicPhotoPolicyTest extends TestCase
{
    private const string A = '0199a1b2-0000-7000-8000-000000000001';
    private const string B = '0199a1b2-0000-7000-8000-000000000002';

    public function test_the_predicate_asks_for_a_live_grant_that_allows_the_listing_and_resized_copies_and_no_open_complaint(): void
    {
        $sql = PublicPhotoPolicy::sql('vp');

        self::assertSame(
            '(EXISTS (SELECT 1 FROM media_rights_grants rg WHERE rg.venue_photo_id = vp.id AND rg.revoked_at IS NULL AND rg.scope_listing AND rg.scope_resized_copies)'
            ." AND NOT EXISTS (SELECT 1 FROM moderation_case_photos mcp JOIN moderation_cases mc ON mc.id = mcp.case_id WHERE mcp.venue_photo_id = vp.id AND mc.status = 'open'))",
            $sql,
        );
    }

    #[DataProvider('plainAliases')]
    public function test_a_plain_alias_is_spliced_in_as_given(string $alias): void
    {
        self::assertStringContainsString('rg.venue_photo_id = '.$alias.'.id', PublicPhotoPolicy::sql($alias));
        self::assertStringContainsString('mcp.venue_photo_id = '.$alias.'.id', PublicPhotoPolicy::sql($alias));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function plainAliases(): iterable
    {
        yield 'one letter' => ['p'];
        yield 'two letters' => ['vp'];
        yield 'with a digit' => ['ph2'];
        yield 'with an underscore' => ['venue_photo'];
    }

    #[DataProvider('foreignAliases')]
    public function test_anything_but_a_plain_alias_is_refused(string $alias): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublicPhotoPolicy::sql($alias);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignAliases(): iterable
    {
        yield 'empty' => [''];
        yield 'leading digit' => ['1p'];
        yield 'leading underscore' => ['_p'];
        yield 'upper case' => ['VP'];
        yield 'qualified' => ['public.vp'];
        yield 'leading space' => [' vp'];
        yield 'trailing space' => ['vp '];
        yield 'two words' => ['vp x'];
        yield 'a quote' => ["vp'"];
        yield 'a double quote' => ['"vp"'];
        yield 'a comment' => ['vp--'];
        yield 'a second statement' => ['vp; DROP TABLE venues'];
        yield 'the alias the predicate uses for the grants' => ['rg'];
        yield 'the alias the predicate uses for the photos of a complaint' => ['mcp'];
        yield 'the alias the predicate uses for the complaints' => ['mc'];
        yield 'a hyphen' => ['venue-photo'];
        yield 'a non-ASCII letter' => ['vä'];
        // PCRE's `$` also matches before a final newline, so the pattern needs the D modifier.
        yield 'a trailing newline' => ["vp\n"];
        yield 'a trailing NUL' => ["vp\0"];
    }

    public function test_filtering_nothing_asks_the_database_nothing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchFirstColumn');

        self::assertSame([], (new PublicPhotoPolicy($connection))->filter([]));
    }

    public function test_ids_that_are_no_uuid_never_reach_the_database(): void
    {
        // A UUID column refuses any other text with SQLSTATE 22P02: the id of a crafted request would be a 500.
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchFirstColumn');

        self::assertSame([], (new PublicPhotoPolicy($connection))->filter(['', 'x', "1' OR '1'='1", self::A.'0', self::A."\n", "\0"]));
    }

    public function test_only_the_ids_that_are_uuids_are_asked_about_and_their_order_is_kept(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchFirstColumn')
            ->with(self::stringContains('FROM venue_photos vp'), ['ids' => [self::B, self::A]], ['ids' => ArrayParameterType::STRING])
            ->willReturn([self::A, self::B]);

        self::assertSame([self::B, self::A], (new PublicPhotoPolicy($connection))->filter(['nope', self::B, 'x', self::A, '']));
    }
}
