<?php

declare(strict_types=1);

namespace App\Tests\Application\Venues;

use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Standort map (#15, DP-10) in both modes. The key is set per test before the client boots,
 * because `GOOGLE_MAPS_EMBED_KEY` is read once when the container builds the extension.
 */
final class VenueMapTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const KEY = 'GOOGLE_MAPS_EMBED_KEY';
    private const SEARCH_URL = 'https://www.google.com/maps/search/?api=1&query=47.366667%2C8.550000';

    private ?KernelBrowser $client = null;
    private EntityManagerInterface $em;
    private mixed $serverKey;
    private mixed $envKey;

    protected function tearDown(): void
    {
        if (null !== $this->client) {
            $this->em->clear();
            $this->em->getConnection()->rollBack();
            $this->client = null;
        }
        parent::tearDown();

        $_SERVER[self::KEY] = $this->serverKey;
        $_ENV[self::KEY] = $this->envKey;
    }

    public function test_without_a_key_the_map_is_a_link_to_the_coordinates(): void
    {
        $this->start('');
        $this->venueAt(47.366667, 8.55);

        $html = $this->html(DetailVenues::FULL_SLUG);
        $crawler = new Crawler($html);

        self::assertCount(1, $crawler->filter('#standort .venue-map'));
        $link = $crawler->filter('.venue-map__link');
        self::assertSame(self::SEARCH_URL, $link->attr('href'));
        self::assertSame('_blank', $link->attr('target'));
        self::assertSame('noopener', $link->attr('rel'));
        self::assertSame('(öffnet in neuem Tab)', trim($link->filter('.visually-hidden')->text()));
        $place = $crawler->filter('.venue-map__place')->text();
        self::assertStringContainsString('Schloss Seeblick', $place);
        self::assertStringContainsString('Seestrasse 1', $place);
        self::assertStringNotContainsString('<template', $html);
        self::assertStringNotContainsString('data-venue-map-loader', $html);
        self::assertStringNotContainsString('maps/embed', $html);
    }

    public function test_with_a_key_the_iframe_waits_in_a_template(): void
    {
        $this->start('test-key');
        $this->venueAt(47.366667, 8.55);

        $html = $this->html(DetailVenues::FULL_SLUG);
        $crawler = new Crawler($html);

        self::assertStringContainsString('src="https://www.google.com/maps/embed/v1/place?key=test-key&amp;q=8FVC9H82%2BM2&amp;zoom=15&amp;language=de"', $html);
        $template = $this->template($html);
        self::assertStringContainsString('loading="lazy"', $template);
        self::assertStringContainsString('referrerpolicy="strict-origin-when-cross-origin"', $template);
        self::assertStringContainsString('allowfullscreen', $template);
        self::assertStringContainsString('title="Karte mit dem Standort von Schloss Seeblick"', $template);
        $button = $crawler->filter('[data-venue-map-loader][hidden] button[data-venue-map-load]');
        self::assertCount(1, $button);
        self::assertSame('Karte laden', trim($button->text()));
        self::assertSame('Beim Laden der Karte werden Daten an Google übertragen.', $crawler->filter('.venue-map__notice')->text());
        self::assertSame(self::SEARCH_URL, $crawler->filter('.venue-map__link')->attr('href'));
    }

    public function test_a_key_with_reserved_characters_is_encoded_and_escaped(): void
    {
        $this->start('a&b"c');
        $this->venueAt(47.366667, 8.55);

        $html = $this->html(DetailVenues::FULL_SLUG);

        self::assertStringContainsString('key=a%26b%22c&amp;q=', $html);
        self::assertStringNotContainsString('a&b"c', $html);
    }

    public function test_nothing_outside_the_template_points_at_google_before_the_click(): void
    {
        $this->start('test-key');
        $this->venueAt(47.366667, 8.55);

        $outside = preg_replace('~<template\b.*?</template>~s', '', $this->html(DetailVenues::FULL_SLUG));
        self::assertIsString($outside);

        self::assertSame(1, substr_count($outside, 'google.'));
        self::assertStringContainsString('href="https://www.google.com/maps/search/', $outside);
        self::assertStringNotContainsString('<iframe', $outside);
        self::assertSame(0, substr_count($outside, 'googleapis'));
        self::assertSame(0, substr_count($outside, 'gstatic'));
    }

    public function test_venues_without_coordinates_get_no_map(): void
    {
        $this->start('test-key');
        $withoutPlace = DetailVenues::withoutPlace();
        $withoutPlace->changeDistances('0.9', 'Bahnhof Beispiel', null, null, null, null);
        DetailVenues::inCantonOnly();
        DetailVenues::inRegionOnly($this->em, 'Schweizweit');
        $this->em->flush();

        foreach (['wiesenhof-ohne-ort', 'scheune-nur-kanton', 'remise-nur-region'] as $slug) {
            $html = $this->html($slug);
            $crawler = new Crawler($html);
            self::assertCount(0, $crawler->filter('.venue-map'), $slug);
            self::assertStringNotContainsString('google.', $html, $slug);
            if ('wiesenhof-ohne-ort' === $slug) {
                self::assertCount(1, $crawler->filter('#standort'));
            }
        }
    }

    public function test_a_town_without_coordinates_keeps_the_address_without_a_map(): void
    {
        $this->start('test-key');
        $venue = DetailVenues::full($this->em);
        $venue->relocate($venue->town(), $venue->zip(), $venue->street(), null, null);
        $this->em->flush();

        $crawler = new Crawler($this->html(DetailVenues::FULL_SLUG));

        self::assertCount(1, $crawler->filter('#standort'));
        self::assertCount(1, $crawler->filter('.venue-location__address'));
        self::assertCount(0, $crawler->filter('.venue-map'));
    }

    public function test_town_centre_coordinates_show_no_map(): void
    {
        $this->start('test-key');
        $venue = $this->venueAt(47.366667, 8.55);
        $this->em->getConnection()->executeStatement('UPDATE venues SET coordinates_from_town = TRUE WHERE number = ?', [$venue->number()]);

        $html = $this->html(DetailVenues::FULL_SLUG);
        $crawler = new Crawler($html);

        self::assertCount(1, $crawler->filter('#standort'));
        self::assertCount(1, $crawler->filter('.venue-location__address'));
        self::assertCount(0, $crawler->filter('.venue-map'));
        self::assertStringNotContainsString('google.', $html);
    }

    public function test_the_map_keeps_the_page_cacheable_and_inline_free(): void
    {
        $this->start('test-key');
        $this->venueAt(47.366667, 8.55);

        $crawler = new Crawler($this->html(DetailVenues::FULL_SLUG));

        \assert(null !== $this->client);
        $response = $this->client->getResponse();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('s-maxage=600', $cacheControl);
        self::assertStringContainsString('stale-while-revalidate=86400', $cacheControl);
        self::assertStringNotContainsString('private', $cacheControl);
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertCount(0, $crawler->filter('[style]'));
        self::assertCount(0, $crawler->filter('style'));
        self::assertCount(0, $crawler->filter('script:not([src]):not([type="application/ld+json"])'));
        self::assertCount(1, $crawler->filter('script[type="module"][src*="venue-map"]'));
    }

    public function test_the_html_is_the_same_on_every_request(): void
    {
        $this->start('test-key');
        $this->venueAt(47.366667, 8.55);

        $first = $this->mapMarkup($this->html(DetailVenues::FULL_SLUG));
        $second = $this->mapMarkup($this->html(DetailVenues::FULL_SLUG));

        self::assertSame($first, $second);
    }

    private function start(string $key): void
    {
        $this->serverKey = $_SERVER[self::KEY] ?? null;
        $this->envKey = $_ENV[self::KEY] ?? null;
        $_SERVER[self::KEY] = $_ENV[self::KEY] = $key;

        $this->client = self::createClient();
        $this->client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->em->getConnection()->beginTransaction();
    }

    private function venueAt(float $lat, float $lng): Venue
    {
        $venue = DetailVenues::full($this->em);
        $venue->relocate($venue->town(), $venue->zip(), $venue->street(), $lat, $lng);
        $this->em->flush();

        return $venue;
    }

    private function html(string $slug): string
    {
        \assert(null !== $this->client);
        $this->client->request('GET', '/hochzeitslocation/'.$slug);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function template(string $html): string
    {
        return $this->match('~<template data-venue-map-frame>.*?</template>~s', $html);
    }

    private function mapMarkup(string $html): string
    {
        return $this->match('~<div class="venue-map" data-venue-map>.*?</template>\s*</div>~s', $html);
    }

    private function match(string $pattern, string $html): string
    {
        if (1 !== preg_match($pattern, $html, $match)) {
            self::fail('No match for '.$pattern);
        }

        return $match[0];
    }
}
