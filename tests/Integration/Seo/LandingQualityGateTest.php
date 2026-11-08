<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Geo\ValueObject\Canton;
use App\Search\LandingCopy;
use App\Search\LandingPaths;
use App\Search\ReadModel\LandingFooter;
use App\Search\ReadModel\LandingPages;
use App\Search\ReadModel\LandingQualityGate;
use App\Seo\Extension\LandingPageCatalogue;
use App\Seo\ListingMeta;
use App\Seo\PagesSitemap;
use App\Seo\ValueObject\SitemapUrl;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Integration\Search\ListingQueryTest;
use App\Tests\Support\Search\LandingFixtures;
use App\Venues\ReadModel\VenueSitemapQuery;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The landing gate (F8 hook, G12 rules): a live landing page that fails LandingQualityGate stays out of the
 * sitemap and is flagged for llms.txt, while a passing one stays in. The gate here asks for 7 entries that pass
 * VenueIndexGate; the threshold that makes a page live stays 5. A canton outside the launch regions is never indexed.
 */
final class LandingQualityGateTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;
    use LandingFixtures;
    /** `%landing.launch_cantons%` (D21), for the tests that build LandingPages by hand. */
    public const array LAUNCH_CANTONS = ['zh', 'ag', 'zg', 'be'];

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_a_page_under_the_gate_leaves_the_sitemap_and_is_not_indexable_in_the_catalogue(): void
    {
        $this->withEventType($this->inCanton(Canton::ZH, 6), 'seminar');
        $this->inCanton(Canton::BE, 8);

        $translator = $this->service(TranslatorInterface::class, 'translator');
        $urls = $this->service(UrlGeneratorInterface::class, 'router');
        $pages = new LandingPages($this->connection, ListingQueryTest::enabledLocale(), $translator, new LandingFooter(new ArrayAdapter()), new LandingQualityGate(7, self::LAUNCH_CANTONS), 5);
        $paths = new LandingPaths($pages, $urls);

        $sitemap = new PagesSitemap($urls, $this->service(VenueSitemapQuery::class), $pages, $paths);
        $locs = array_map(static fn (SitemapUrl $url): string => (string) parse_url($url->loc, \PHP_URL_PATH), $sitemap->urls());
        self::assertContains('/eventlokale/bern', $locs);
        self::assertNotContains('/eventlokale/zuerich', $locs);
        self::assertNotContains('/eventlokale/seminar', $locs);
        self::assertNotContains('/eventlokale/zuerich/seminar', $locs);
        self::assertNotNull($pages->canton(Canton::ZH), 'The page is still live.');

        $catalogue = new LandingPageCatalogue($pages, $paths, $this->service(ListingMeta::class), $translator, new LandingCopy($translator), 5);
        self::assertTrue($catalogue->page('canton.be')?->indexable);
        self::assertFalse($catalogue->page('canton.zh')?->indexable);
        self::assertFalse($catalogue->page('event.seminar.zh')?->indexable);
        self::assertTrue($catalogue->page('event.seminar.zh')->passesThreshold);
    }

    public function test_the_launch_regions_are_the_configured_cantons(): void
    {
        self::assertSame(self::LAUNCH_CANTONS, self::getContainer()->getParameter('landing.launch_cantons'));
        self::assertSame(5, self::getContainer()->getParameter('landing.index_min_venues'), 'the test env lowers it to the fixtures\' 5');
    }

    public function test_only_entries_that_pass_the_index_gate_count(): void
    {
        // Zürich: 9 published, but 2 unchecked and 1 without a description, so 6 count: under 7.
        $zurich = $this->inCanton(Canton::ZH, 6);
        foreach ($this->inCanton(Canton::ZH, 2) as $venue) {
            $venue->confirmFacts(false, new \DateTimeImmutable());
        }
        $this->connection->executeStatement('UPDATE venue_translations SET description = NULL WHERE venue_id = :id', ['id' => $zurich[0]->id()]);
        $this->inCanton(Canton::ZH, 1);
        // Bern: 7 that count.
        $this->inCanton(Canton::BE, 7);
        $this->em->flush();

        $pages = $this->pages();
        self::assertSame(9, $pages->canton(Canton::ZH)?->venues);
        self::assertFalse($pages->canton(Canton::ZH)->indexable);
        self::assertTrue($pages->canton(Canton::BE)?->indexable);
    }

    public function test_a_canton_outside_the_launch_regions_is_live_but_never_indexable(): void
    {
        $this->withEventType($this->inCanton(Canton::TI, 8), 'seminar');

        $pages = $this->pages();
        self::assertFalse($pages->canton(Canton::TI)?->indexable, 'Ticino is no launch region');
        self::assertFalse($pages->event('seminar', Canton::TI)?->indexable, 'an event page in Ticino is in a canton too');
        self::assertTrue($pages->event('seminar')?->indexable, 'the national event page counts every canton');
    }

    private function pages(): LandingPages
    {
        $translator = $this->service(TranslatorInterface::class, 'translator');

        return new LandingPages($this->connection, ListingQueryTest::enabledLocale(), $translator, new LandingFooter(new ArrayAdapter()), new LandingQualityGate(7, self::LAUNCH_CANTONS), 5);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class, ?string $id = null): object
    {
        $service = self::getContainer()->get($id ?? $class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
