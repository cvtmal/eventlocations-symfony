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
 * The G12 hook (F8): a live landing page that fails LandingQualityGate stays out of the
 * sitemap and is flagged for llms.txt, while a passing one stays in. The gate here asks for
 * 7 venues; the threshold that makes a page live stays 5.
 */
final class LandingQualityGateTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;
    use LandingFixtures;

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
        $pages = new LandingPages($this->connection, ListingQueryTest::enabledLocale(), $translator, new LandingFooter(new ArrayAdapter()), new LandingQualityGate(7), 5);
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
