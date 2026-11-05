<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Geo\ValueObject\Canton;
use App\Search\LandingPaths;
use App\Search\ReadModel\KnownTermsQuery;
use App\Search\ReadModel\LandingFooter;
use App\Search\ReadModel\LandingPages;
use App\Search\ReadModel\SearchCriteria;
use App\Search\ValueObject\LandingType;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Search\LandingFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The path-facet rule against live pages built in a rolled-back transaction: ZH and the town
 * Stäfa (5 venues each, Stäfa's count towards ZH), and 5 castles in Bern (so BE is live too).
 */
final class LandingPathsTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;
    use LandingFixtures;

    private EntityManagerInterface $em;
    private Connection $connection;
    private LandingPaths $paths;
    private string $staefa;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();

        $this->inTown('Stäfa', 5);
        $this->withBuilding($this->inCanton(Canton::BE, 5), 'castle');
        $this->staefa = $this->town('Stäfa')->id();

        $translator = self::getContainer()->get('translator');
        $urls = self::getContainer()->get('router');
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        self::assertInstanceOf(UrlGeneratorInterface::class, $urls);
        $pages = new LandingPages($this->connection, ListingQueryTest::enabledLocale(), $translator, new LandingFooter(new ArrayAdapter()), 5);
        $this->paths = new LandingPaths($pages, $urls);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_a_town_with_radius_zero_is_its_town_page(): void
    {
        $query = $this->query(['place' => $this->staefa, 'radius' => '0', 'guests' => '100']);
        [$page, $rest] = $this->paths->locate($query);

        self::assertSame(LandingType::Town, $page?->type);
        self::assertSame(['guests' => '100'], $rest);
        self::assertSame('/hochzeitslocations/zuerich/staefa?guests=100', $this->paths->url($query));
        self::assertSame('/hochzeitslocations/zuerich/staefa', $this->paths->url($this->query(['canton' => 'zh', 'place' => $this->staefa, 'radius' => '0'])));
    }

    public function test_a_town_with_a_radius_is_no_town_page(): void
    {
        self::assertSame(
            '/hochzeitslocations?place='.$this->staefa,
            $this->paths->url($this->query(['place' => $this->staefa])),
            'The default radius 5 reaches beyond the town.',
        );
        self::assertSame(
            '/hochzeitslocations/zuerich?place='.$this->staefa.'&radius=10',
            $this->paths->url($this->query(['canton' => 'zh', 'place' => $this->staefa, 'radius' => '10'])),
        );
    }

    public function test_a_town_in_another_canton_falls_to_the_canton_page_and_keeps_place(): void
    {
        $query = $this->query(['canton' => 'be', 'place' => $this->staefa, 'radius' => '0']);
        [$page, $rest] = $this->paths->locate($query);

        self::assertSame(Canton::BE, $page?->canton);
        self::assertSame(LandingType::Canton, $page->type);
        self::assertSame(['place' => $this->staefa, 'radius' => '0'], $rest);
    }

    public function test_a_live_canton_is_its_canton_page(): void
    {
        self::assertSame('/hochzeitslocations/zuerich', $this->paths->url($this->query(['canton' => 'zh'])));
        self::assertSame('/hochzeitslocations/bern?building%5B0%5D=castle', $this->paths->url($this->query(['canton' => 'be', 'building' => ['castle']])), 'The canton wins over the building.');
        self::assertMatchesRegularExpression('~^http://localhost(:\d+)?/hochzeitslocations/zuerich$~', $this->paths->url($this->query(['canton' => 'zh']), UrlGeneratorInterface::ABSOLUTE_URL));
    }

    public function test_a_thin_canton_stays_in_the_query(): void
    {
        self::assertSame('/hochzeitslocations?canton=ti', $this->paths->url($this->query(['canton' => 'ti'])));
    }

    public function test_exactly_one_live_building_is_its_building_page(): void
    {
        self::assertSame('/hochzeitslocations/schloss?guests=100', $this->paths->url($this->query(['guests' => '100', 'building' => ['castle']])));
        self::assertSame('/hochzeitslocations?building%5B0%5D=castle&building%5B1%5D=hotel', $this->paths->url($this->query(['building' => ['castle', 'hotel']])));
        self::assertSame('/hochzeitslocations?building%5B0%5D=hotel', $this->paths->url($this->query(['building' => ['hotel']])));
    }

    public function test_nothing_live_is_the_plain_listing(): void
    {
        [$page, $rest] = $this->paths->locate([]);

        self::assertNull($page);
        self::assertSame([], $rest);
        self::assertSame('/hochzeitslocations', $this->paths->url([]));
    }

    public function test_the_remainder_keeps_page_and_the_codec_order(): void
    {
        $query = $this->query([
            'height' => '3', 'curfew' => 'none', 'price' => ['medium'], 'usage' => ['exclusive'],
            'setting' => ['lake'], 'q' => 'see', 'guests' => '100', 'canton' => 'zh', 'page' => '2',
        ]);
        [, $rest] = $this->paths->locate($query);

        self::assertSame(['guests', 'q', 'setting', 'usage', 'price', 'curfew', 'height', 'page'], array_keys($rest));
        $without = $query;
        unset($without['canton']);
        self::assertSame($without, $rest);
    }

    /**
     * @param array<string, string|list<string>> $parameters
     *
     * @return array<string, string|list<string>>
     */
    private function query(array $parameters): array
    {
        /** @var InputBag<string> $query */
        $query = new InputBag($parameters);

        return SearchCriteria::fromQuery($query, new KnownTermsQuery($this->connection)->all())->toQuery();
    }
}
