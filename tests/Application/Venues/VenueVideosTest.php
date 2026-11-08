<?php

declare(strict_types=1);

namespace App\Tests\Application\Venues;

use App\Factory\VenueVideoFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\ValueObject\KnownTiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Videos section (G15) as the rendered HTML source shows it: every player waits inert in a <template>, so before
 * the click the page names no player host outside one, loads no script of YouTube or Vimeo and shows no preview image;
 * the provider links work without JavaScript; the plan's `videos_max` (F12) cuts the list; the CSP admits the two
 * players.
 */
final class VenueVideosTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    public function test_a_pro_page_shows_each_video_as_a_facade_with_the_player_inert_in_a_template(): void
    {
        $venue = DetailVenues::minimal(tier: KnownTiers::PRO);
        VenueVideoFactory::createOne(['venue' => $venue, 'position' => 2, 'embed' => VenueVideoFactory::vimeo('76979871')]);
        VenueVideoFactory::createOne(['venue' => $venue, 'position' => 1, 'embed' => VenueVideoFactory::youtube('aB3_dE-fGh1')]);

        $html = $this->html();
        $crawler = new Crawler($html);

        self::assertCount(1, $crawler->filter('section#videos'));
        self::assertSame('Videos', $crawler->filter('#venue-videos-title')->text());
        self::assertSame(['Video 1 auf YouTube', 'Video 2 auf Vimeo'], $crawler->filter('.venue-video__caption')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(
            ['https://www.youtube.com/watch?v=aB3_dE-fGh1', 'https://vimeo.com/76979871'],
            $crawler->filter('.venue-video__link')->each(static fn (Crawler $a): string => (string) $a->attr('href')),
        );
        self::assertSame(
            ['Video laden (YouTube)', 'Video laden (Vimeo)'],
            $crawler->filter('[data-venue-video-loader][hidden] button[data-venue-video-load]')->each(static fn (Crawler $b): string => $b->text()),
        );
        self::assertCount(2, $crawler->filter('.venue-video__facade svg.venue-video__icon'));
        preg_match_all('~<template data-venue-video-frame>(.*?)</template>~s', $html, $templates);
        self::assertCount(2, $templates[1]);
        self::assertStringContainsString('src="https://www.youtube-nocookie.com/embed/aB3_dE-fGh1?autoplay=1"', $templates[1][0]);
        self::assertStringContainsString('src="https://player.vimeo.com/video/76979871?dnt=1&amp;autoplay=1"', $templates[1][1]);
        self::assertStringContainsString('title="Video 1 von Gartenhaus Minimal"', $templates[1][0]);
        self::assertStringContainsString('referrerpolicy="strict-origin-when-cross-origin"', $templates[1][0]);
        self::assertCount(1, $crawler->filter('.venue-nav a[href="#videos"]'));
        self::assertCount(1, $crawler->filter('script[type="module"][src*="venue-video"]'));
    }

    public function test_nothing_outside_the_templates_reaches_youtube_or_vimeo_before_the_click(): void
    {
        $venue = DetailVenues::minimal(tier: KnownTiers::PRO);
        VenueVideoFactory::createOne(['venue' => $venue, 'position' => 1, 'embed' => VenueVideoFactory::youtube('aB3_dE-fGh1')]);
        VenueVideoFactory::createOne(['venue' => $venue, 'position' => 2, 'embed' => VenueVideoFactory::vimeo('76979871')]);

        $outside = preg_replace('~<template\b.*?</template>~s', '', $this->html());
        self::assertIsString($outside);
        $crawler = new Crawler($outside);

        self::assertCount(0, $crawler->filter('iframe'));
        // The only mention of a provider outside a template is the link a visitor clicks; nothing that loads by itself.
        foreach (['youtube-nocookie', 'player.vimeo', 'ytimg', 'vimeocdn', 'youtube.com/iframe_api', 'youtube.com/embed'] as $host) {
            self::assertStringNotContainsString($host, $outside, $host);
        }
        foreach ($crawler->filter('[src], [srcset], [poster], [data], link[href], [style]') as $element) {
            \assert($element instanceof \DOMElement);
            foreach (['src', 'srcset', 'poster', 'data', 'href', 'style'] as $attribute) {
                if ('href' !== $attribute || 'link' === $element->tagName) {
                    self::assertDoesNotMatchRegularExpression('~youtu|vimeo~i', $element->getAttribute($attribute), 'loads by itself: '.$attribute);
                }
            }
        }
        self::assertSame(2, preg_match_all('~youtube\.com|vimeo\.com~', $outside));
    }

    public function test_a_free_page_shows_the_first_video_only_and_keeps_the_second_stored(): void
    {
        $venue = DetailVenues::minimal(tier: KnownTiers::FREE);
        VenueVideoFactory::createOne(['venue' => $venue, 'position' => 1, 'embed' => VenueVideoFactory::youtube('aB3_dE-fGh1')]);
        VenueVideoFactory::createOne(['venue' => $venue, 'position' => 2, 'embed' => VenueVideoFactory::vimeo('76979871')]);

        $html = $this->html();

        self::assertSame(1, substr_count($html, 'data-venue-video-frame'));
        self::assertStringContainsString('aB3_dE-fGh1', $html);
        self::assertStringNotContainsString('76979871', $html);
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM venue_videos WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_a_page_without_videos_has_no_section_no_nav_item_and_no_script(): void
    {
        DetailVenues::minimal(tier: KnownTiers::PRO);

        $crawler = new Crawler($this->html());

        self::assertCount(0, $crawler->filter('#videos'));
        self::assertCount(0, $crawler->filter('.venue-nav a[href="#videos"]'));
        self::assertCount(0, $crawler->filter('script[src*="venue-video"]'));
    }

    public function test_the_csp_admits_the_two_players_and_nothing_inline(): void
    {
        $venue = DetailVenues::minimal(tier: KnownTiers::PRO);
        VenueVideoFactory::createOne(['venue' => $venue, 'position' => 1]);

        $crawler = new Crawler($this->html());
        $csp = (string) $this->client->getResponse()->headers->get('Content-Security-Policy');

        self::assertStringContainsString('frame-src https://www.google.com https://www.youtube-nocookie.com https://player.vimeo.com;', $csp);
        self::assertStringContainsString("script-src 'self';", $csp);
        self::assertCount(0, $crawler->filter('[style]'));
        self::assertCount(0, $crawler->filter('script:not([src]):not([type="application/ld+json"])'));
    }

    private function html(): string
    {
        $this->client->request('GET', '/eventlokal/'.DetailVenues::MINIMAL_SLUG);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }
}
