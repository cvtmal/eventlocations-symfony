<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Geo\ValueObject\Canton;
use App\Search\ReadModel\LandingPage;
use App\Search\ReadModel\LandingPages;
use App\Seo\ListingMeta;
use App\Shared\Extension\LandingPageSource;
use App\Shared\ValueObject\LandingPageEntry;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Search\LandingFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The back office's view of #17's landing pages (#26): every candidate with at least one
 * venue, the live ones exactly LandingPages::all() (one counting SQL), with #17's texts as
 * defaults. Each test runs in a rolled-back transaction; the threshold is 5 in test.
 */
final class LandingPageCatalogueTest extends KernelTestCase
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

    public function test_the_entries_over_the_threshold_are_the_live_landing_pages(): void
    {
        $this->withBuilding($this->inCanton(Canton::ZH, 5), 'castle');
        $this->inCanton(Canton::BE, 2);
        $this->inTown('Stäfa', 1);

        $live = array_map(
            static fn (LandingPage $page): string => $page->keyPrefix(),
            $this->service(LandingPages::class)->all(),
        );
        $passing = array_map(
            static fn (LandingPageEntry $entry): string => $entry->keyPrefix,
            array_values(array_filter($this->catalogue()->pages(), static fn (LandingPageEntry $entry): bool => $entry->passesThreshold)),
        );

        sort($live);
        sort($passing);
        self::assertSame(['building.castle', 'canton.zh'], $live);
        self::assertSame($live, $passing);
        self::assertCount(4, $this->catalogue()->pages(), 'Bern and Stäfa are candidates under the threshold.');
    }

    public function test_an_entry_carries_its_heading_count_url_and_defaults(): void
    {
        $this->inCanton(Canton::ZH, 5);
        $this->inCanton(Canton::BE, 2);

        $zh = $this->catalogue()->page('canton.zh');
        self::assertNotNull($zh);
        self::assertSame('canton', $zh->type);
        self::assertSame('Hochzeitslocations im Kanton Zürich', $zh->name);
        self::assertSame(5, $zh->venueCount);
        self::assertTrue($zh->passesThreshold);
        self::assertNotNull($zh->url);
        self::assertStringEndsWith('/hochzeitslocations/zuerich', $zh->url);
        self::assertStringStartsWith('http', $zh->url);
        self::assertStringStartsWith('5 Hochzeitslocations im Kanton Zürich:', $zh->defaultIntro);
        self::assertSame('landing.canton.zh.title', $zh->blockKey('title'));

        $page = $this->service(LandingPages::class)->canton(Canton::ZH);
        self::assertNotNull($page);
        self::assertSame(
            $this->service(ListingMeta::class)->defaults($page),
            ['title' => $zh->defaultTitle, 'description' => $zh->defaultDescription],
        );

        $be = $this->catalogue()->page('canton.be');
        self::assertNotNull($be);
        self::assertFalse($be->passesThreshold);
        self::assertNull($be->url, 'A page under the threshold has no public URL.');
        self::assertSame(2, $be->venueCount);
    }

    public function test_a_page_with_one_venue_reads_in_the_singular(): void
    {
        $this->withBuilding($this->inTown('Stäfa', 1), 'castle');

        self::assertSame('1 Hochzeitslocation im Kanton Zürich | Hochzeitslocation', $this->catalogue()->page('canton.zh')?->defaultTitle);
        self::assertSame('Hochzeit im Schloss: 1 Location | Hochzeitslocation', $this->catalogue()->page('building.castle')?->defaultTitle);
        $town = array_values(array_filter($this->catalogue()->pages(), static fn (LandingPageEntry $entry): bool => 'town' === $entry->type));
        self::assertCount(1, $town);
        self::assertSame('1 Hochzeitslocation in Stäfa | Hochzeitslocation', $town[0]->defaultTitle);
    }

    public function test_two_venues_keep_the_plural(): void
    {
        $this->withBuilding($this->inTown('Stäfa', 2), 'castle');

        self::assertSame('2 Hochzeitslocations im Kanton Zürich | Hochzeitslocation', $this->catalogue()->page('canton.zh')?->defaultTitle);
        self::assertSame('Hochzeit im Schloss: 2 Locations | Hochzeitslocation', $this->catalogue()->page('building.castle')?->defaultTitle);
        $town = array_values(array_filter($this->catalogue()->pages(), static fn (LandingPageEntry $entry): bool => 'town' === $entry->type));
        self::assertCount(1, $town);
        self::assertSame('2 Hochzeitslocations in Stäfa | Hochzeitslocation', $town[0]->defaultTitle);
    }

    public function test_an_unknown_prefix_has_no_entry(): void
    {
        $this->inCanton(Canton::ZH, 5);

        self::assertNull($this->catalogue()->page('canton.xx'));
        self::assertNull($this->catalogue()->page('canton.ge'), 'A canton without a published venue is no candidate.');
    }

    public function test_entries_are_ordered_by_type_then_name(): void
    {
        $this->withBuilding($this->inCanton(Canton::ZH, 1), 'castle');
        $this->inCanton(Canton::BE, 3);
        $this->inTown('Stäfa', 1);
        $this->inTown('Bern', 1);

        self::assertSame(
            ['canton:Hochzeitslocations im Kanton Bern', 'canton:Hochzeitslocations im Kanton Zürich', 'town:Hochzeitslocations in Bern', 'town:Hochzeitslocations in Stäfa', 'building:Hochzeit im Schloss'],
            array_map(static fn (LandingPageEntry $entry): string => $entry->type.':'.$entry->name, $this->catalogue()->pages()),
        );
    }

    private function catalogue(): LandingPageSource
    {
        return $this->service(LandingPageSource::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
