<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use App\Cms\Command\SaveLandingTexts;
use App\Cms\Command\SaveTextBlock;
use App\Cms\ValueObject\ContentVersion;
use App\Tests\Integration\Venues\DispatchesVenueCommands;
use App\Tests\Support\Cms\ResetsContentBlocks;
use App\Tests\Support\Http\PublicRouteFixtures;
use App\Tests\Support\Http\ThroughHttpCache;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Command\ChangeVenueTexts;
use App\Venues\Command\ChangeVenueTier;
use App\Venues\Command\DeleteVenue;
use App\Venues\Command\PurgeListingsCache;
use App\Venues\Command\PurgeVenueCache;
use App\Venues\Command\UnpublishVenue;
use App\Venues\ValueObject\KnownTiers;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * S10, §19: a CMS save reaches every visitor at once, because each purge invalidates the whole store.
 * A page answers from HttpCache before the save and from the app after it.
 */
final class HttpCachePurgeTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use DispatchesVenueCommands;
    use Factories;
    use PublicRouteFixtures;
    use ResetsContentBlocks;
    use ThroughHttpCache;

    private const DETAIL = '/eventlokal/'.DetailVenues::FULL_SLUG;
    private const LISTING = '/eventlokale';
    private const CANTON = '/eventlokale/zuerich';
    private const HOME = '/';
    private const SUMMARY = 'Ein frischer Satz, den noch kein Cache gesehen hat.';
    private const STAFF_TEXT = 'Ein Text aus dem CMS, den noch kein Cache gesehen hat.';

    private Connection $connection;
    private EntityManagerInterface $em;
    private int $number;

    protected function setUp(): void
    {
        self::mockTime(self::FROZEN_NOW);
        $kernel = self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->number = $this->seedPublicRoutes()['number'];
        $this->resetContentBlocks();
        $this->startHttpCache($kernel);
    }

    protected function tearDown(): void
    {
        $this->stopHttpCache();
        $this->resetContentBlocks();
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_a_cms_save_replaces_the_cached_pages(): void
    {
        foreach ([self::DETAIL, self::LISTING, self::CANTON] as $page) {
            $this->assertTrace('miss/store', $this->get($page), $page);
            $this->assertTrace('fresh', $this->get($page), $page);
            self::assertStringNotContainsString(self::SUMMARY, (string) $this->get($page)->getContent());
        }

        $this->dispatch(new ChangeVenueTexts($this->number, $this->token($this->number), ['summary'], [
            'de' => ['summary' => self::SUMMARY, 'description' => null, 'metaDescription' => null, 'parkingText' => null],
        ]));

        foreach ([self::DETAIL, self::LISTING, self::CANTON] as $page) {
            $response = $this->get($page);
            $this->assertTrace('miss/store', $response, $page);
            self::assertStringContainsString(self::SUMMARY, (string) $response->getContent(), $page);
        }
    }

    /**
     * F12: a tier decides what a page shows (the phone, the label), so changing it must reach the visitor at once.
     * The seeded venue is on Pro; on Free its detail page loses the reveal link and its listing card the label.
     */
    public function test_a_tier_change_replaces_the_cached_pages(): void
    {
        foreach ([self::DETAIL, self::LISTING] as $page) {
            $this->assertTrace('miss/store', $this->get($page), $page);
            $this->assertTrace('fresh', $this->get($page), $page);
        }
        self::assertStringContainsString('/telefon', (string) $this->get(self::DETAIL)->getContent());
        self::assertStringContainsString('Top-Eintrag', (string) $this->get(self::LISTING)->getContent());

        $this->dispatch(new ChangeVenueTier($this->number, KnownTiers::FREE));

        $detail = $this->get(self::DETAIL);
        $this->assertTrace('miss/store', $detail, self::DETAIL);
        self::assertStringNotContainsString('/telefon', (string) $detail->getContent());
        $listing = $this->get(self::LISTING);
        $this->assertTrace('miss/store', $listing, self::LISTING);
        self::assertStringNotContainsString('Top-Eintrag', (string) $listing->getContent());
    }

    /**
     * #26's ContentBlockWriter (both commands) purges through PurgeListingsCache; the footer
     * note sits on every page, so the detail page must miss too, not only the listing pages.
     */
    public function test_a_text_block_save_replaces_the_cached_pages(): void
    {
        foreach ([self::DETAIL, self::LISTING] as $page) {
            $this->get($page);
            $this->assertTrace('fresh', $this->get($page), $page);
        }

        $this->dispatch(new SaveTextBlock('footer.note', ['de' => self::STAFF_TEXT], $this->contentVersion(['footer.note']), ['de']));

        foreach ([self::DETAIL, self::LISTING] as $page) {
            $response = $this->get($page);
            $this->assertTrace('miss/store', $response, $page);
            self::assertStringContainsString(self::STAFF_TEXT, (string) $response->getContent(), $page);
        }
    }

    public function test_a_landing_text_save_replaces_the_cached_pages(): void
    {
        foreach ([self::CANTON, self::DETAIL] as $page) {
            $this->get($page);
            $this->assertTrace('fresh', $this->get($page), $page);
        }

        $keys = ['landing.canton.zh.intro', 'landing.canton.zh.title', 'landing.canton.zh.description'];
        $this->dispatch(new SaveLandingTexts('canton.zh', ['de' => ['intro' => self::STAFF_TEXT]], $this->contentVersion($keys), ['intro'], ['de']));

        $response = $this->get(self::CANTON);
        $this->assertTrace('miss/store', $response, self::CANTON);
        self::assertStringContainsString(self::STAFF_TEXT, (string) $response->getContent());
        $this->assertTrace('miss/store', $this->get(self::DETAIL), self::DETAIL);
    }

    /** The first `fresh` is the automated half of "answers from the cache on a second request". */
    public function test_a_home_text_save_replaces_the_cached_home_page(): void
    {
        $this->assertTrace('miss/store', $this->get(self::HOME), self::HOME);
        $this->assertTrace('fresh', $this->get(self::HOME), self::HOME);

        $this->dispatch(new SaveTextBlock('home.text', ['de' => self::STAFF_TEXT], $this->contentVersion(['home.text']), ['de']));

        $response = $this->get(self::HOME);
        $this->assertTrace('miss/store', $response, self::HOME);
        self::assertStringContainsString(self::STAFF_TEXT, (string) $response->getContent());
    }

    public function test_a_venue_change_replaces_the_cached_home_page(): void
    {
        $this->assertTrace('miss/store', $this->get(self::HOME), self::HOME);
        $this->assertTrace('fresh', $this->get(self::HOME), self::HOME);

        $this->dispatch(new ChangeVenueTexts($this->number, $this->token($this->number), ['summary'], [
            'de' => ['summary' => self::SUMMARY, 'description' => null, 'metaDescription' => null, 'parkingText' => null],
        ]));

        $response = $this->get(self::HOME);
        $this->assertTrace('miss/store', $response, self::HOME);
        self::assertStringContainsString(self::SUMMARY, (string) $response->getContent());
    }

    public function test_a_listings_purge_alone_invalidates_the_store(): void
    {
        $this->get(self::DETAIL);
        $this->assertTrace('fresh', $this->get(self::DETAIL), self::DETAIL);

        $this->dispatch(new PurgeListingsCache());

        $this->assertTrace('miss/store', $this->get(self::DETAIL), self::DETAIL);
    }

    public function test_a_venue_purge_alone_invalidates_the_store(): void
    {
        $this->get(self::DETAIL);
        $this->assertTrace('fresh', $this->get(self::DETAIL), self::DETAIL);

        $this->dispatch(new PurgeVenueCache($this->number));

        $this->assertTrace('miss/store', $this->get(self::DETAIL), self::DETAIL);
    }

    public function test_an_unpublished_venue_stops_answering_from_the_cache(): void
    {
        self::assertSame(Response::HTTP_OK, $this->get(self::DETAIL)->getStatusCode());
        $this->assertTrace('fresh', $this->get(self::DETAIL), self::DETAIL);

        $this->dispatch(new UnpublishVenue($this->number));

        $response = $this->get(self::DETAIL);
        self::assertSame(Response::HTTP_GONE, $response->getStatusCode());
        $this->assertTrace('miss/store', $response, self::DETAIL);
    }

    /**
     * DeleteVenue sends only PurgeListingsCache: the one handler that relies on the listings
     * purge also reaching a detail page.
     */
    public function test_a_deleted_venue_stops_answering_from_the_cache(): void
    {
        $this->dispatch(new UnpublishVenue($this->number));
        self::assertSame(Response::HTTP_GONE, $this->get(self::DETAIL)->getStatusCode());
        $this->assertTrace('fresh', $this->get(self::DETAIL), self::DETAIL);

        $this->dispatch(new DeleteVenue($this->number, 'Zunftsaal'));

        $response = $this->get(self::DETAIL);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertTrace('miss/store', $response, self::DETAIL);
    }

    public function test_a_purge_removes_the_metadata_and_keeps_the_bodies(): void
    {
        $this->get(self::DETAIL);
        $shareDirectory = self::getContainer()->getParameter('kernel.share_dir');
        self::assertIsString($shareDirectory);
        $directory = $shareDirectory.'/http_cache';
        self::assertDirectoryExists($directory.'/md');
        self::assertDirectoryExists($directory.'/en');

        $this->dispatch(new PurgeListingsCache());

        self::assertDirectoryDoesNotExist($directory.'/md');
        self::assertDirectoryExists($directory.'/en');
    }

    /**
     * @param list<string> $keys
     */
    private function contentVersion(array $keys): string
    {
        /** @var list<array{key: string, locale: string, updated_at: string, body_markdown: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT key, locale, updated_at, body_markdown FROM content_blocks WHERE key IN (?)',
            [$keys],
            [ArrayParameterType::STRING],
        );

        return ContentVersion::of($rows);
    }

    private function get(string $path): Response
    {
        return $this->through(Request::create($path));
    }

    private function assertTrace(string $expected, Response $response, string $page): void
    {
        self::assertSame($expected, $response->headers->get('X-Symfony-Cache'), $page);
    }
}
