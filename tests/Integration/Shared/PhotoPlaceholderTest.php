<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Search\ReadModel\ListingCard;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenuePlace;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * `components/_photo_placeholder` (F6, D12 follow-up 1): the neutral stand-in for a venue without a public photo. The event
 * type is a plain code, so F3's vocabulary, F5's filter and F7b's cards can feed it; every code the partial maps must name
 * a symbol of the sprite, and everything else must fall back to the generic icon.
 */
final class PhotoPlaceholderTest extends KernelTestCase
{
    public function test_it_is_decorative_and_carries_no_text_or_link(): void
    {
        $crawler = new Crawler($this->placeholder());
        $root = $crawler->filter('span.photo-placeholder');

        self::assertCount(1, $root);
        self::assertSame('true', $root->attr('aria-hidden'));
        self::assertSame('', trim($root->text()));
        self::assertCount(0, $crawler->filter('a, button, img, [alt], [aria-label]'));
        self::assertCount(1, $root->filter('svg.icon.photo-placeholder__icon'));
    }

    public function test_without_an_event_type_it_shows_the_generic_icon(): void
    {
        self::assertSame('sparkles', $this->iconOf($this->placeholder()));
    }

    #[DataProvider('mapped')]
    public function test_an_event_type_picks_its_icon(string $code, string $icon): void
    {
        self::assertSame($icon, $this->iconOf($this->placeholder($code)), $code);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mapped(): iterable
    {
        $pairs = [
            'corporate_event' => 'building-2', 'team_event' => 'users', 'general_assembly' => 'users', 'seminar' => 'presentation',
            'conference' => 'presentation', 'workshop' => 'presentation', 'trade_fair' => 'landmark', 'birthday' => 'cake',
            'private_event' => 'party-popper', 'apero' => 'wine', 'christmas_party' => 'tree-pine', 'wedding' => 'heart',
            'concert' => 'music', 'sports_event' => 'trophy',
        ];
        foreach ($pairs as $code => $icon) {
            yield $code => [$code, $icon];
        }
    }

    #[DataProvider('unknown')]
    public function test_an_unknown_empty_or_missing_code_gets_the_generic_icon(?string $code): void
    {
        self::assertSame('sparkles', $this->iconOf($this->placeholder($code)));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function unknown(): iterable
    {
        yield 'unknown' => ['hot_air_balloon_regatta'];
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'a known code in another case' => ['Birthday'];
    }

    public function test_the_class_of_the_place_is_appended(): void
    {
        $html = $this->render('components/_photo_placeholder.html.twig', ['class' => 'card__art']);

        self::assertCount(1, (new Crawler($html))->filter('span.photo-placeholder.card__art'));
        self::assertSame('photo-placeholder', (new Crawler($this->placeholder()))->filter('span')->attr('class'));
    }

    public function test_every_icon_of_the_map_is_a_symbol_of_the_sprite(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 3).'/templates/components/_photo_placeholder.html.twig');
        if (1 !== preg_match('/set icons = \{(.*?)\} -%\}/s', $source, $block)) {
            self::fail('The partial holds the icon map.');
        }
        preg_match_all("/([a-z_]+): '([a-z0-9-]+)'/", $block[1], $pairs, \PREG_SET_ORDER);
        self::assertGreaterThan(20, \count($pairs));

        $sprite = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/icons/sprite.svg');
        preg_match_all('/<symbol id="([a-z0-9-]+)"/', $sprite, $ids);
        if (1 !== preg_match("/default\\('([a-z0-9-]+)'\\)/", $source, $generic)) {
            self::fail('The partial names a generic icon.');
        }

        foreach ($pairs as [, $code, $icon]) {
            self::assertContains($icon, $ids[1], "{$code} names the icon {$icon}, which the sprite lacks.");
        }
        self::assertContains($generic[1], $ids[1], 'The generic icon is in the sprite.');
    }

    public function test_a_card_without_photos_shows_it_in_its_photo_link_and_passes_the_event_type(): void
    {
        $crawler = new Crawler($this->card(['event_type' => 'birthday']));

        self::assertCount(1, $crawler->filter('a.card__photo-link.card__placeholder[aria-hidden="true"][tabindex="-1"] > .photo-placeholder'));
        self::assertSame('cake', $this->iconOf($crawler->filter('.card__placeholder')->html()));
        self::assertCount(0, $crawler->filter('.carousel'));
    }

    public function test_the_card_of_the_merkliste_shows_it_too(): void
    {
        $crawler = new Crawler($this->card(['action' => 'remove']));

        self::assertCount(1, $crawler->filter('.card__placeholder .photo-placeholder'));
        self::assertSame('sparkles', $this->iconOf($crawler->filter('.card__placeholder')->html()));
        self::assertCount(1, $crawler->filter('form'));
    }

    private function placeholder(?string $code = null): string
    {
        return $this->render('components/_photo_placeholder.html.twig', null === $code ? [] : ['event_type' => $code]);
    }

    private function iconOf(string $html): string
    {
        $href = (new Crawler($html))->filter('svg.photo-placeholder__icon use')->attr('href');

        return substr((string) strstr((string) $href, '#'), 1);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function card(array $context = []): string
    {
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
            photos: [],
        );

        return $this->render('search/_card.html.twig', ['card' => $card] + $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context): string
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->render($template, $context);
    }
}
