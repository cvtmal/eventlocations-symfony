<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * D48: /datenschutz explains the Google rating and the Google photos only while their switch is on
 * (`GOOGLE_RATING_ENABLED`, a non-empty `GOOGLE_PLACES_PHOTOS_KEY`), and the Maps title and Google's processor line
 * name only what is on, so the page is always true to the code. f16: the same for the detail page's "Karte laden"
 * (`GOOGLE_MAPS_EMBED_KEY`) and the near-me map (`GOOGLE_MAPS_JS_KEY`); the rating loads from Google only with the
 * JS key, else it is a plain link named under "Links"; with nothing from Google Maps, the section is left out.
 * Every variable is set before the client boots.
 */
final class DatenschutzGoogleSwitchesTest extends WebTestCase
{
    private const string INTRO = 'Google Maps ist ein Dienst der Google Ireland Limited';
    private const string MAP = 'Die Karte laden wir erst, wenn du auf «Karte laden» klickst';
    private const string NEARBY = 'Die Seite «In der Nähe» lädt die Karte von Google Maps sofort beim Aufruf';
    private const string RATING = 'Auf der Seite einer Location kann die Google-Bewertung erscheinen';
    private const string RATING_LINK = 'Ebenso führt der Link «Bewertungen auf Google Maps ansehen» zu den Bewertungen der Location';
    private const string PHOTOS = 'Diese Fotos ruft unser Server bei Google ab';

    /** @var array<string, array{mixed, mixed}> */
    private array $saved = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->saved as $name => [$server, $env]) {
            [$_SERVER[$name], $_ENV[$name]] = [$server, $env];
        }
        $this->saved = [];
    }

    /**
     * Rating switch, photos key, embed key, JS key; the expected title (null: no section) and the paragraphs shown.
     *
     * @return iterable<string, array{string, string, string, string, ?string, list<string>}>
     */
    public static function states(): iterable
    {
        yield 'everything off (production default)' => ['0', '', '', '', null, []];
        yield 'embed key only' => ['0', '', 'embed', '', 'Karten von Google Maps', [self::MAP]];
        yield 'JS key only' => ['0', '', '', 'js', 'Karten von Google Maps', [self::NEARBY]];
        yield 'both map keys' => ['0', '', 'embed', 'js', 'Karten von Google Maps', [self::MAP, self::NEARBY]];
        yield 'rating on, no JS key (a link only)' => ['1', '', '', '', null, [self::RATING_LINK]];
        yield 'rating on, embed key only' => ['1', '', 'embed', '', 'Karten von Google Maps', [self::MAP, self::RATING_LINK]];
        yield 'rating live' => ['1', '', '', 'js', 'Karten und Bewertungen von Google Maps', [self::NEARBY, self::RATING]];
        yield 'photos only' => ['0', 'places', '', '', 'Fotos von Google Maps', [self::PHOTOS]];
        yield 'photos and the link-only rating' => ['1', 'places', '', '', 'Fotos von Google Maps', [self::PHOTOS, self::RATING_LINK]];
        yield 'photos and the embed map' => ['0', 'places', 'embed', '', 'Karten und Fotos von Google Maps', [self::MAP, self::PHOTOS]];
        yield 'everything on' => ['1', 'places', 'embed', 'js', 'Karten, Bewertungen und Fotos von Google Maps', [self::MAP, self::NEARBY, self::RATING, self::PHOTOS]];
    }

    /** @param list<string> $shown */
    #[DataProvider('states')]
    public function test_the_google_section_names_only_what_google_delivers(string $rating, string $photosKey, string $embedKey, string $jsKey, ?string $title, array $shown): void
    {
        $crawler = $this->page([
            'GOOGLE_RATING_ENABLED' => $rating,
            'GOOGLE_PLACES_PHOTOS_KEY' => $photosKey,
            'GOOGLE_MAPS_EMBED_KEY' => $embedKey,
            'GOOGLE_MAPS_JS_KEY' => $jsKey,
        ]);
        $text = $crawler->filter('main')->text();
        $headings = $crawler->filter('main h2')->each(static fn (Crawler $h): string => $h->text());

        self::assertContains(
            // G15: YouTube videos are click-to-load, not Places content, so no switch hides them.
            'Google Ireland Limited: '.(null === $title ? '' : $title.', ').'Videos von YouTube (Irland)',
            $crawler->filter('.legal__processors li')->each(static fn (Crawler $item): string => $item->text()),
        );
        if (null === $title) {
            self::assertEmpty(array_filter($headings, static fn (string $h): bool => str_contains($h, 'Google Maps')), 'no Google Maps section');
            self::assertStringNotContainsString(self::INTRO, $text);
        } else {
            self::assertContains($title, $headings);
            self::assertStringContainsString(self::INTRO, $text);
            self::assertStringContainsString('policies.google.com/privacy', $text);
        }
        foreach ([self::MAP, self::NEARBY, self::RATING, self::RATING_LINK, self::PHOTOS] as $paragraph) {
            self::assertSame(\in_array($paragraph, $shown, true), str_contains($text, $paragraph), $paragraph);
        }
        if (\in_array(self::RATING_LINK, $shown, true)) {
            // The sentence quotes the detail page's link, so it must follow it.
            $translator = self::getContainer()->get(TranslatorInterface::class);
            self::assertInstanceOf(TranslatorInterface::class, $translator);
            self::assertStringContainsString('«'.$translator->trans('detail.google_rating.link', [], 'venues', 'de').'»', $text);
        }
        // The detail page always links to Google Maps; the links section says so.
        self::assertStringContainsString('«Auf Google Maps öffnen»', $text);
    }

    /** @param array<string, string> $vars */
    private function page(array $vars): Crawler
    {
        foreach ($vars as $name => $value) {
            $this->saved[$name] ??= [$_SERVER[$name] ?? null, $_ENV[$name] ?? null];
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
        $client = self::createClient();

        $crawler = $client->request('GET', '/datenschutz');
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
