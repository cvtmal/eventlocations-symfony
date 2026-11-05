<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Geo\ReadModel\PlaceResolver;
use App\Geo\ValueObject\Canton;
use App\Media\ValueObject\ImageFormat;
use App\Search\LandingPaths;
use App\Search\ReadModel\CardPhoto;
use App\Search\ReadModel\FilterDataQuery;
use App\Search\ReadModel\KnownTerms;
use App\Search\ReadModel\LandingFooter;
use App\Search\ReadModel\LandingPages;
use App\Search\ReadModel\SearchCriteria;
use App\Search\Twig\ListingExtension;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Search\LandingFixtures;
use App\Venues\ValueObject\RatingLabel;
use App\Venues\ValueObject\TermGroup;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Zenstruck\Foundry\Test\Factories;

/**
 * The listing's Twig helpers against the real router and translator, plus the
 * hidden-state partial that #10–#12's GET forms use.
 */
final class ListingExtensionTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;
    use LandingFixtures;

    private const PHOTO_ID = '0198a3c4-6b1e-7f3a-9c2d-1e4f5a6b7c8d';

    private ListingExtension $extension;

    protected function setUp(): void
    {
        $this->extension = $this->extension();
    }

    /**
     * By hand, not from the container: a private service used once may be inlined there. Built
     * after a test's fixtures, because LandingPages memoizes the live pages.
     */
    private function extension(): ListingExtension
    {
        $urls = self::getContainer()->get('router');
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(UrlGeneratorInterface::class, $urls);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $landings = new LandingPages($connection, ListingQueryTest::enabledLocale(), $translator, new LandingFooter(new ArrayAdapter()), 5);

        return new ListingExtension($urls, $translator, new PlaceResolver($connection), new FilterDataQuery($connection, ListingQueryTest::enabledLocale()), $landings, new LandingPaths($landings, $urls));
    }

    public function test_listing_paths_take_the_landing_path_of_a_live_canton(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->beginTransaction();
        try {
            $this->inCanton(Canton::ZH, 5);
            $extension = $this->extension();
            $criteria = $this->criteria(['canton' => 'zh', 'guests' => '100']);

            self::assertSame('/hochzeitslocations/zuerich?guests=100', $extension->listingPath($criteria));
            self::assertSame('/hochzeitslocations/zuerich?guests=100&page=2', $extension->listingPath($criteria, 2), 'Pagination keeps the path.');
            self::assertSame('/hochzeitslocations?guests=100', $extension->listingPathWithout($criteria, 'canton'));
            self::assertSame('/hochzeitslocations/zuerich', $extension->listingPathWithout($criteria, 'guests'));
            self::assertSame('/hochzeitslocations?canton=be', $extension->listingPath($this->criteria(['canton' => 'be'])), 'A thin canton stays in the query.');
            self::assertSame(['zh' => '/hochzeitslocations/zuerich'], $extension->landingCantonPaths());
        } finally {
            $em->clear();
            $em->getConnection()->rollBack();
        }
    }

    public function test_listing_path_with_and_without_a_page(): void
    {
        $criteria = $this->criteria(['guests' => '120', 'canton' => 'ZH', 'page' => '3']);

        self::assertSame('/hochzeitslocations?canton=zh&guests=150&page=3', $this->extension->listingPath($criteria));
        self::assertSame('/hochzeitslocations?canton=zh&guests=150&page=2', $this->extension->listingPath($criteria, 2));
        self::assertSame('/hochzeitslocations?canton=zh&guests=150', $this->extension->listingPath($criteria, 1));
        self::assertSame('/hochzeitslocations', $this->extension->listingPath(SearchCriteria::none()));
    }

    public function test_list_params_round_trip_through_the_codec(): void
    {
        $criteria = $this->criteria(['setting' => ['river', 'lake'], 'access' => 'station']);
        $path = $this->extension->listingPath($criteria);

        self::assertSame('/hochzeitslocations?setting%5B0%5D=lake&setting%5B1%5D=river&access%5B0%5D=station', $path);

        self::assertSame($criteria->toQuery(), $this->criteria(Request::create($path)->query->all())->toQuery());
    }

    public function test_selected_place(): void
    {
        self::assertNull($this->extension->selectedPlace(SearchCriteria::none()));
        self::assertSame('Stäfa', $this->extension->selectedPlace($this->criteria(['place' => '8712']))?->name);
        self::assertNull($this->extension->selectedPlace($this->criteria(['place' => self::PHOTO_ID])));
    }

    public function test_filter_terms_keeps_an_active_code_without_data(): void
    {
        self::assertSame([], $this->extension->filterTerms(TermGroup::Setting, SearchCriteria::none()));
        self::assertSame(['river'], $this->extension->filterTerms(TermGroup::Setting, $this->criteria(['setting' => ['river']])));
    }

    public function test_listing_path_without(): void
    {
        $criteria = $this->criteria([
            'canton' => 'zh', 'place' => '8001', 'radius' => '10', 'guests' => '100',
            'setting' => ['lake', 'river'], 'page' => '2',
        ]);

        self::assertSame(
            '/hochzeitslocations?place=8001&radius=10&guests=100&setting%5B0%5D=lake&setting%5B1%5D=river',
            $this->extension->listingPathWithout($criteria, 'canton'),
        );
        self::assertSame(
            '/hochzeitslocations?canton=zh&place=8001&radius=10&guests=100&setting%5B0%5D=river',
            $this->extension->listingPathWithout($criteria, 'setting', 'lake'),
        );
        self::assertSame(
            '/hochzeitslocations?canton=zh&guests=100&setting%5B0%5D=lake&setting%5B1%5D=river',
            $this->extension->listingPathWithout($criteria, ['place', 'radius']),
        );
        self::assertSame(
            '/hochzeitslocations?canton=zh&place=8001&radius=10&guests=100',
            $this->extension->listingPathWithout($this->criteria([
                'canton' => 'zh', 'place' => '8001', 'radius' => '10', 'guests' => '100', 'setting' => ['river'],
            ]), 'setting', 'river'),
            'Removing the last value drops the key.',
        );
        self::assertSame(
            '/hochzeitslocations?canton=zh&place=8001&radius=10&guests=100&setting%5B0%5D=lake&setting%5B1%5D=river',
            $this->extension->listingPathWithout($criteria, 'rooms'),
            'An absent param changes nothing but page.',
        );
        self::assertSame(
            '/hochzeitslocations?place=8001&radius=10&guests=100&setting%5B0%5D=lake&setting%5B1%5D=river',
            $this->extension->listingPathWithout($criteria, 'canton', 'zh'),
            'A scalar equal to the value is dropped.',
        );
        self::assertSame(
            '/hochzeitslocations?canton=zh&place=8001&radius=10&guests=100&setting%5B0%5D=lake&setting%5B1%5D=river',
            $this->extension->listingPathWithout($criteria, 'canton', 'be'),
        );
    }

    public function test_card_picture(): void
    {
        self::assertNull($this->extension->cardPicture(CardPhoto::fromRow(7, $this->photoRow(false), null), 'Seehaus'));

        $fallback = $this->extension->cardPicture(CardPhoto::fromRow(7, $this->photoRow(true), null), 'Seehaus');
        self::assertNotNull($fallback);
        self::assertSame('Seehaus, Bild 3', $fallback->alt);
        self::assertSame(7, $fallback->venueNumber);
        self::assertSame(self::PHOTO_ID, $fallback->photoId);
        self::assertSame([360, 640, 960, 1280], $fallback->variants->widths);
        self::assertSame('/media/7/'.self::PHOTO_ID.'-640.webp', $fallback->url(640, ImageFormat::Webp));

        $stored = $this->extension->cardPicture(CardPhoto::fromRow(7, $this->photoRow(true), 'Seeterrasse'), 'Seehaus');
        self::assertNotNull($stored);
        self::assertSame('Seeterrasse', $stored->alt);
    }

    public function test_rating_label_and_venue_path(): void
    {
        self::assertSame(RatingLabel::Outstanding, $this->extension->ratingLabel(9.0));
        self::assertNull($this->extension->ratingLabel(7.0));
        self::assertSame('/hochzeitslocation/schloss-au', $this->extension->venuePath('schloss-au'));
    }

    public function test_hidden_state_keeps_the_other_groups(): void
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $criteria = $this->criteria(['canton' => 'zh', 'guests' => '100', 'setting' => ['lake', 'river'], 'q' => 'See', 'page' => '2']);

        $crawler = new Crawler($twig->render('search/filter/_hidden_state.html.twig', ['criteria' => $criteria, 'except' => ['guests', 'q']]));
        $inputs = $crawler->filter('input')->each(
            static fn (Crawler $input): array => [$input->attr('type'), $input->attr('name'), $input->attr('value')],
        );

        self::assertSame([
            ['hidden', 'canton', 'zh'],
            ['hidden', 'setting[]', 'lake'],
            ['hidden', 'setting[]', 'river'],
        ], $inputs);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function criteria(array $query): SearchCriteria
    {
        /** @var InputBag<string> $bag the shape Request::$query has */
        $bag = new InputBag($query);

        return SearchCriteria::fromQuery($bag, KnownTerms::of(['setting' => ['lake', 'river', 'city']]));
    }

    /**
     * @return array<string, mixed>
     */
    private function photoRow(bool $withVariants): array
    {
        $row = ['id' => self::PHOTO_ID, 'position' => 3, 'original_path' => 'demo/seehaus.jpg'];
        if ($withVariants) {
            $row += [
                'width' => 1600,
                'height' => 1067,
                'dominant_color' => '#b9a58c',
                'variants' => ['widths' => [360, 640, 960, 1280], 'formats' => ['avif', 'webp', 'jpg']],
            ];
        }

        return $row;
    }
}
