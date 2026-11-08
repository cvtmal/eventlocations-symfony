<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * D48: /datenschutz explains the Google rating and the Google photos only while their switch is on
 * (`GOOGLE_RATING_ENABLED`, a non-empty `GOOGLE_PLACES_PHOTOS_KEY`), and the Maps title and Google's processor line
 * name only what is on, so the page is always true to the code. Both are set before the client boots.
 */
final class DatenschutzGoogleSwitchesTest extends WebTestCase
{
    private const array VARS = ['GOOGLE_RATING_ENABLED', 'GOOGLE_PLACES_PHOTOS_KEY'];
    private const string RATING = 'Auf der Seite einer Location kann die Google-Bewertung erscheinen';
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

    /** @return iterable<string, array{string, string, string, bool, bool}> */
    public static function states(): iterable
    {
        yield 'both off (production default)' => ['0', '', 'Karten von Google Maps', false, false];
        yield 'rating only' => ['1', '', 'Karten und Bewertungen von Google Maps', true, false];
        yield 'photos only' => ['0', 'test-places-key', 'Karten und Fotos von Google Maps', false, true];
        yield 'both on' => ['1', 'test-places-key', 'Karten, Bewertungen und Fotos von Google Maps', true, true];
    }

    #[DataProvider('states')]
    public function test_the_google_section_names_only_what_is_switched_on(string $rating, string $photosKey, string $title, bool $ratingShown, bool $photosShown): void
    {
        foreach (array_combine(self::VARS, [$rating, $photosKey]) as $name => $value) {
            $this->saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null];
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
        $client = self::createClient();

        $crawler = $client->request('GET', '/datenschutz');
        self::assertResponseIsSuccessful();
        $text = $crawler->filter('main')->text();

        self::assertContains($title, $crawler->filter('main h2')->each(static fn (Crawler $h): string => $h->text()));
        self::assertContains(
            // G15: YouTube videos are click-to-load, not Places content, so no D48 switch hides them.
            'Google Ireland Limited: '.$title.', Videos von YouTube (Irland)',
            $crawler->filter('.legal__processors li')->each(static fn (Crawler $item): string => $item->text()),
        );
        self::assertSame($ratingShown, str_contains($text, self::RATING), 'rating paragraph');
        self::assertSame($photosShown, str_contains($text, self::PHOTOS), 'photos paragraph');
        // The map paragraphs do not depend on either switch.
        self::assertStringContainsString('Die Karte laden wir erst, wenn du auf «Karte laden» klickst', $text);
        self::assertStringContainsString('policies.google.com/privacy', $text);
    }
}
