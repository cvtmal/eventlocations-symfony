<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Search\ReadModel\CardPhoto;
use App\Search\ReadModel\ListingCard;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenuePlace;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * The `heading` parameter of `search/_card` (#37). A page that has its own h2 above the cards passes h3; the listing's
 * default stays h2. Rendered on its own, because no page passes `heading` yet. The same goes for the track's tab stop:
 * demo data has no venue with one photo.
 */
final class CardPartialTest extends KernelTestCase
{
    public function test_the_title_is_an_h2_by_default(): void
    {
        $crawler = new Crawler($this->render());

        self::assertCount(1, $crawler->filter('h2.card__title'));
        self::assertCount(0, $crawler->filter('h3'));
    }

    public function test_a_caller_can_make_the_title_an_h3(): void
    {
        $crawler = new Crawler($this->render(['heading' => 'h3']));

        self::assertCount(1, $crawler->filter('h3.card__title'));
        self::assertCount(0, $crawler->filter('h2'));
        self::assertSame('Premium', $crawler->filter('h3.card__title .badge')->text());
        self::assertSame('Schloss Seeburg', $crawler->filter('h3.card__title .card__link')->text());
    }

    public function test_the_default_renders_the_same_html_as_an_explicit_h2(): void
    {
        self::assertSame($this->render(), $this->render(['heading' => 'h2']));
    }

    public function test_a_track_with_one_photo_is_no_tab_stop(): void
    {
        $crawler = new Crawler($this->render(photos: 1));

        self::assertCount(1, $crawler->filter('.carousel__track .carousel__slide'));
        self::assertNull($crawler->filter('.carousel__track')->attr('tabindex'));
    }

    public function test_a_track_with_several_photos_keeps_its_tab_stop(): void
    {
        $crawler = new Crawler($this->render(photos: 3));

        self::assertCount(3, $crawler->filter('.carousel__track .carousel__slide'));
        self::assertSame('0', $crawler->filter('.carousel__track')->attr('tabindex'));
    }

    public function test_only_the_first_slide_keeps_its_alt_text(): void
    {
        $alts = (new Crawler($this->render(photos: 3)))->filter('.carousel__slide img')->each(
            static fn (Crawler $img): ?string => $img->attr('alt'),
        );

        self::assertNotSame('', $alts[0]);
        self::assertNotNull($alts[0]);
        self::assertSame(['', ''], \array_slice($alts, 1));
    }

    public function test_a_distance_adds_a_third_fact(): void
    {
        $crawler = new Crawler($this->render(['distance' => '4 km']));

        $facts = $crawler->filter('.card-facts__item');
        self::assertCount(3, $facts);
        self::assertSame('Entfernung 4 km', $facts->eq(2)->text());
        self::assertCount(2, (new Crawler($this->render()))->filter('.card-facts__item'));
    }

    /**
     * @param array<string, string> $context
     */
    private function render(array $context = [], int $photos = 0): string
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $card = new ListingCard(
            id: '0190b7a0-0000-7000-8000-000000000001',
            number: 1,
            slug: 'schloss-seeburg',
            name: 'Schloss Seeburg',
            tier: Tier::Premium,
            capacityMin: 50,
            capacityMax: 450,
            place: new VenuePlace('Stäfa', null, null),
            summary: 'Ein Schloss am See.',
            priceLevel: null,
            ambience: null,
            photos: $photos > 0 ? array_map($this->photo(...), range(1, $photos)) : [],
        );

        return $twig->render('search/_card.html.twig', ['card' => $card] + $context);
    }

    private function photo(int $position): CardPhoto
    {
        return CardPhoto::fromRow(1, [
            'id' => \sprintf('0190b7a0-0000-7000-8000-%012d', $position),
            'position' => $position,
            'original_path' => 'originals/1/photo.jpg',
            'width' => 1200,
            'height' => 800,
            'dominant_color' => '#a0a0a0',
            'variants' => ['widths' => [360], 'formats' => ['jpg']],
        ], 'Foto');
    }
}
