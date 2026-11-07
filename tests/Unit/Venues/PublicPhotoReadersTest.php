<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A tripwire for G1's invariant, "no public photo without a grant": every class in `src/` that reads
 * `venue_photos` with SQL either calls `PublicPhotoPolicy` (`sql()` in its query, or `filter()` on the
 * ids it found) or is listed here with the reason it may show every stored photo. A comment that only
 * names the policy does not count. A new public read model that forgets the policy fails this
 * test, whoever writes it. The behaviour of the five public readers is proven row by row in
 * tests/Integration/Venues/PublicPhotoPolicyTest.
 */
final class PublicPhotoReadersTest extends TestCase
{
    /**
     * Where a stored photo may be read without the policy: a path prefix or a file, and why.
     */
    private const array NOT_PUBLIC = [
        'src/Cms/' => 'the back office: staff sees every stored photo, behind the admin login',
        'src/Portal/' => 'the location managers: a manager sees every stored photo of the venues they manage, behind the login',
        'src/Venues/PublicPhotoPolicy.php' => 'the policy itself',
        'src/Venues/ReadModel/PhotoRightsQuery.php' => 'the rights card of staff and managers: every stored photo with its grant',
        'src/Venues/ReadModel/ModerationCaseQuery.php' => 'the complaints queue of staff (G2): the photos a complaint names, held or not, behind the admin login',
        'src/Venues/CommandHandler/GrantPhotoRightsHandler.php' => 'the write side: it asks which photos have no live grant yet',
        'src/Venues/ReadModel/VenuePublishGaps.php' => 'the publish rule asks whether the gallery has a stored photo, granted or not',
        'src/Venues/Repository/VenueRepository.php' => 'file bookkeeping: the originals only this venue uses',
        'src/Venues/VenueRows.php' => 'file bookkeeping: whether any row still uses an original',
    ];

    /** The readers a visitor reaches: the home hero, the cards, the similar venues, the detail page and the sitemap. */
    private const array PUBLIC_READERS = [
        'src/Search/ReadModel/HomeHeroQuery.php',
        'src/Search/ReadModel/ListingQuery.php',
        'src/Search/ReadModel/SimilarVenuesQuery.php',
        'src/Venues/ReadModel/VenueDetailQuery.php',
        'src/Venues/ReadModel/VenueSitemapQuery.php',
    ];

    public function test_every_class_that_reads_the_photos_for_the_public_uses_the_policy(): void
    {
        $readers = $this->readersOfVenuePhotos();

        foreach (self::PUBLIC_READERS as $path) {
            self::assertContains($path, array_keys($readers), $path.' reads venue_photos, so this test has to see it');
        }
        foreach ($readers as $path => $code) {
            if (null !== $this->reasonItMayShowEveryPhoto($path)) {
                continue;
            }
            self::assertTrue(
                self::usesThePolicy($code),
                $path.' reads venue_photos for the public without calling PublicPhotoPolicy::sql() or an injected policy\'s filter() (a mention in a comment is no call): put the predicate in its query, or list the file in NOT_PUBLIC with the reason it may show photos nobody has granted.',
            );
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function sources(): iterable
    {
        yield 'the predicate spliced into the query' => [<<<'PHP'
            <?php
            final class Query
            {
                public function sql(): string
                {
                    return 'SELECT p.id FROM venue_photos p WHERE '.PublicPhotoPolicy::sql('p');
                }
            }
            PHP, true];
        yield 'an injected policy that filters the ids the query found' => [<<<'PHP'
            <?php
            final class Query
            {
                public function __construct(private readonly PublicPhotoPolicy $policy)
                {
                }

                public function ids(array $found): array
                {
                    return $this->policy->filter($found);
                }
            }
            PHP, true];
        yield 'a docblock that names the policy' => [<<<'PHP'
            <?php
            /**
             * Reads venue_photos. PublicPhotoPolicy::sql('p') would be the right filter.
             */
            final class Query
            {
                public function sql(): string
                {
                    return 'SELECT p.id FROM venue_photos p';
                }
            }
            PHP, false];
        yield 'a line comment and a block comment that call it' => [<<<'PHP'
            <?php
            final class Query
            {
                public function sql(): string
                {
                    // TODO PublicPhotoPolicy::sql('p')
                    /* $this->policy->filter($ids) */
                    return 'SELECT p.id FROM venue_photos p';
                }
            }
            PHP, false];
        yield 'a policy that is injected and never asked' => [<<<'PHP'
            <?php
            final class Query
            {
                public function __construct(private readonly PublicPhotoPolicy $policy, private readonly Collection $photos)
                {
                }

                public function ids(): array
                {
                    return $this->photos->filter(static fn ($photo) => true);
                }
            }
            PHP, false];
        yield 'an import of the policy and nothing else' => [<<<'PHP'
            <?php
            use App\Venues\PublicPhotoPolicy;

            final class Query
            {
                public function sql(): string
                {
                    return 'SELECT p.id FROM venue_photos p';
                }
            }
            PHP, false];
    }

    #[DataProvider('sources')]
    public function test_the_check_tells_a_use_of_the_policy_from_a_mention_of_it(string $code, bool $uses): void
    {
        self::assertSame($uses, self::usesThePolicy($code));
    }

    public function test_the_list_of_exceptions_has_no_dead_entry(): void
    {
        $root = \dirname(__DIR__, 3);

        foreach (array_keys(self::NOT_PUBLIC) as $path) {
            self::assertTrue(is_file($root.'/'.$path) || is_dir($root.'/'.$path), $path.' is listed but gone: remove the entry.');
        }
    }

    public function test_the_public_readers_name_the_policy_not_a_copy_of_its_sql(): void
    {
        $readers = $this->readersOfVenuePhotos();

        foreach (self::PUBLIC_READERS as $path) {
            self::assertStringNotContainsString('media_rights_grants', $readers[$path] ?? '', $path.' asks the policy; the grants table is spelled in one place.');
            self::assertMatchesRegularExpression('/PublicPhotoPolicy::sql\(\'[a-z][a-z0-9_]*\'\)/', self::withoutComments($readers[$path] ?? ''), $path.' calls the policy (a mention in a comment is no call)');
        }
    }

    /**
     * Whether the code puts the policy to work: it splices `PublicPhotoPolicy::sql()` into a query, or has the
     * injected policy `filter()` the ids it found. A docblock or a comment that names the policy proves
     * nothing, so comments are cut out first.
     */
    private static function usesThePolicy(string $code): bool
    {
        $code = self::withoutComments($code);
        if (str_contains($code, 'PublicPhotoPolicy::sql(')) {
            return true;
        }

        return 1 === preg_match('/\bPublicPhotoPolicy\s+\$(\w+)/', $code, $injected)
            && 1 === preg_match('/(?:\$this->|\$)'.preg_quote($injected[1], '/').'->filter\(/', $code);
    }

    private static function withoutComments(string $code): string
    {
        $stripped = '';
        foreach (token_get_all($code) as $token) {
            if (\is_array($token)) {
                if (\T_COMMENT === $token[0] || \T_DOC_COMMENT === $token[0]) {
                    continue;
                }
                $token = $token[1];
            }
            $stripped .= $token;
        }

        return $stripped;
    }

    /**
     * The source of every class in `src/` that selects from or joins `venue_photos`, by path.
     *
     * @return array<string, string>
     */
    private function readersOfVenuePhotos(): array
    {
        $root = \dirname(__DIR__, 3);
        $readers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (1 === preg_match('/\b(?:FROM|JOIN)\s+venue_photos\b/i', $code)) {
                $readers[substr($file->getPathname(), \strlen($root) + 1)] = $code;
            }
        }
        ksort($readers);

        return $readers;
    }

    private function reasonItMayShowEveryPhoto(string $path): ?string
    {
        foreach (self::NOT_PUBLIC as $listed => $reason) {
            if ($path === $listed || (str_ends_with($listed, '/') && str_starts_with($path, $listed))) {
                return $reason;
            }
        }

        return null;
    }
}
