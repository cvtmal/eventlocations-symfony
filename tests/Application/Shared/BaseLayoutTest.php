<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The base layout (#1): structure only, no German strings (|trans renders keys until #2 is in).
 */
final class BaseLayoutTest extends WebTestCase
{
    public function test_html_has_a_lang_attribute(): void
    {
        $crawler = $this->home();

        self::assertNotSame('', (string) $crawler->filter('html')->attr('lang'));
    }

    public function test_viewport_allows_zoom(): void
    {
        $crawler = $this->home();

        self::assertSame('width=device-width, initial-scale=1', $crawler->filter('meta[name="viewport"]')->attr('content'));
    }

    public function test_skip_link_is_the_first_focusable_element_and_targets_main(): void
    {
        $crawler = $this->home();

        $first = $crawler->filter('body')->filter('a, button')->first();
        self::assertSame('a', $first->nodeName());
        self::assertStringContainsString('skip-link', (string) $first->attr('class'));
        self::assertSame('#main', $first->attr('href'));
        self::assertSelectorExists('main#main');
    }

    public function test_page_has_exactly_one_h1_and_the_wordmark_is_not_it(): void
    {
        $crawler = $this->home();

        self::assertCount(1, $crawler->filter('h1'));
        self::assertSame('a', $crawler->filter('.site-header__wordmark')->nodeName());
    }

    public function test_header_has_menu_nav_and_favorites_with_hidden_count_badge(): void
    {
        $crawler = $this->home();

        self::assertSelectorExists('[popovertarget="main-menu"]');
        self::assertSelectorExists('#main-menu[popover]');
        self::assertSame(
            ['/eventlokale', '/#regionen', '/eventlokal-in-meiner-naehe', '/merkliste', '/location-eintragen'],
            $crawler->filter('.site-header__actions a')->each(static fn (Crawler $a): ?string => $a->attr('href')),
        );
        self::assertCount(0, $crawler->filter('.site-header use[href$="#search"]'));
        self::assertSelectorExists('.site-header__favorites [data-merkliste-count][hidden]');
    }

    public function test_the_menu_has_its_six_links_and_a_second_badge_but_no_second_merkliste_link(): void
    {
        $crawler = $this->home();

        self::assertSame(
            ['/', '/eventlokale', '/#regionen', '/merkliste', '/eventlokal-in-meiner-naehe', '/kurzfristig-frei'],
            $crawler->filter('.main-menu__list a')->each(static fn (Crawler $a): ?string => $a->attr('href')),
        );
        self::assertSelectorExists('.main-menu__list [data-merkliste-count][hidden]');
        self::assertCount(1, $crawler->filter('[data-merkliste-link]'));
    }

    public function test_the_open_menu_does_not_repeat_the_headers_landmark_name(): void
    {
        $crawler = $this->home();

        $header = $crawler->filter('.site-header__actions')->attr('aria-label');
        $menu = $crawler->filter('.main-menu__nav')->attr('aria-label');

        self::assertNotEmpty($header);
        self::assertNotEmpty($menu);
        self::assertNotSame($header, $menu);
    }

    public function test_the_logos_are_inline_svgs_that_state_their_size_so_nothing_shifts(): void
    {
        $crawler = $this->home();

        foreach (['.site-header__wordmark svg.logo', '.site-footer__brand svg.logo'] as $selector) {
            $logo = $crawler->filter($selector);
            self::assertCount(1, $logo, $selector);
            self::assertSame('img', $logo->attr('role'), $selector);
            self::assertNotSame('', (string) $logo->attr('aria-label'), $selector);
            self::assertSame('286', $logo->attr('width'), $selector);
            self::assertSame('32', $logo->attr('height'), $selector);
        }
    }

    public function test_the_header_prints_the_logo_art_once_and_the_footer_reuses_it(): void
    {
        $crawler = $this->home();

        self::assertCount(1, $crawler->filter('#logo-art'));
        self::assertCount(1, $crawler->filter('.site-header__wordmark #logo-art'));
        self::assertSame('#logo-art', $crawler->filter('.site-footer__brand svg.logo use')->attr('href'));
        self::assertCount(0, $crawler->filter('.site-footer__brand svg.logo path'));
    }

    public function test_the_logos_and_the_copyright_line_name_the_domain_with_the_current_year(): void
    {
        $crawler = $this->home();

        foreach (['.site-header__wordmark svg.logo', '.site-footer__brand svg.logo'] as $selector) {
            self::assertSame('deine-eventlocation.ch', $crawler->filter($selector)->attr('aria-label'));
        }

        $year = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Zurich')))->format('Y');
        self::assertSame("© {$year} deine-eventlocation.ch", $crawler->filter('.site-footer__copy')->text());
    }

    public function test_locations_is_the_current_page_on_the_listing_only(): void
    {
        $crawler = $this->home();

        self::assertSame('/eventlokale', $crawler->filter('.site-header__link[aria-current="page"]')->attr('href'));
        self::assertSame('/eventlokale', $crawler->filter('.main-menu__list a[aria-current="page"]')->attr('href'));

        foreach (['/merkliste', '/impressum'] as $uri) {
            $crawler = $this->client()->request('GET', $uri);
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('.site-header [aria-current]'), $uri);
        }
    }

    public function test_in_der_naehe_is_the_current_page_on_the_near_me_page_only(): void
    {
        $this->client = self::createClient();
        $crawler = $this->client->request('GET', '/eventlokal-in-meiner-naehe');
        self::assertResponseIsSuccessful();

        self::assertSame(['/eventlokal-in-meiner-naehe'], $crawler->filter('.site-header__actions [aria-current="page"]')->each(static fn (Crawler $a): ?string => $a->attr('href')));
        self::assertSame('/eventlokal-in-meiner-naehe', $crawler->filter('.main-menu__list a[aria-current="page"]')->attr('href'));
    }

    public function test_location_eintragen_links_to_the_tier_page_in_all_three_places(): void
    {
        $crawler = $this->home();

        foreach (['.site-header__actions a.btn--primary', '.main-menu__add-location a.btn', 'a.site-footer__add-location'] as $selector) {
            $entry = $crawler->filter($selector);
            self::assertCount(1, $entry, $selector);
            self::assertSame('/location-eintragen', $entry->attr('href'), $selector);
        }
    }

    public function test_the_footer_has_no_link_on_a_heading_and_mails_the_contact_address(): void
    {
        $crawler = $this->home();

        self::assertCount(2, $crawler->filter('.site-footer__column span.site-footer__heading'));
        self::assertCount(0, $crawler->filter('.site-footer__heading a'));
        self::assertSame('mailto:hallo@deine-eventlocation.ch', $crawler->filter('.site-footer__column a[href^="mailto:"]')->attr('href'));
        self::assertSame('/#regionen', $crawler->filter('.site-footer__column a')->eq(1)->attr('href'));
        self::assertSame('/eventlokal-in-meiner-naehe', $crawler->filter('.site-footer__column a')->eq(2)->attr('href'));
    }

    public function test_header_heart_links_to_the_merkliste_and_carries_its_strings(): void
    {
        $crawler = $this->home();

        $heart = $crawler->filter('.site-header__favorites[data-merkliste-link]');
        self::assertCount(1, $heart->filter('.icon'));
        self::assertSame('/merkliste', $heart->attr('href'));
        // The name comes from the content, so the badge's count is part of it (axe label-content-name-mismatch, #41).
        self::assertNull($heart->attr('aria-label'));
        self::assertSame('Merkliste', $heart->filter('.visually-hidden')->text());
        self::assertStringContainsString('__name__', (string) $heart->attr('data-toast-added'));
        self::assertStringContainsString('__name__', (string) $heart->attr('data-toast-removed'));
        self::assertNotSame('', (string) $heart->attr('data-toast-full'));
        self::assertNotSame('', (string) $heart->attr('data-toast-link'));
        self::assertSame('csrf-token', $heart->attr('data-sync-token'));
    }

    public function test_the_merkliste_module_loads_once(): void
    {
        $crawler = $this->home();

        self::assertCount(1, $crawler->filter('script[type="module"][src*="merkliste"]'));
    }

    public function test_footer_and_toast_region_are_present(): void
    {
        $this->home();

        self::assertSelectorExists('footer.site-footer');
        self::assertSelectorExists('[data-toast-region][role="status"][aria-live="polite"]');
    }

    public function test_stylesheet_and_font_preload_use_versioned_asset_urls(): void
    {
        $this->home();

        self::assertSelectorExists('link[rel="stylesheet"][href^="/assets/styles/app-"][href$=".css"]');
        self::assertSelectorExists('link[rel="preload"][as="font"][type="font/woff2"][crossorigin][href^="/assets/fonts/hanken-grotesk-latin-wght-normal-"]');
    }

    public function test_the_brand_head_links_the_icons_the_manifest_and_the_theme_colour(): void
    {
        $this->home();

        self::assertSelectorExists('link[rel="icon"][href="/brand/favicon.svg"][type="image/svg+xml"]');
        self::assertSelectorExists('link[rel="icon"][href="/brand/favicon.ico"][sizes="48x48"]');
        self::assertSelectorExists('link[rel="apple-touch-icon"][href="/brand/apple-touch-icon.png"]');
        self::assertSelectorExists('link[rel="manifest"][href="/brand/site.webmanifest"]');
        self::assertSelectorExists('meta[name="theme-color"][content="#f6f7fb"]');
    }

    public function test_page_has_no_inline_styles_or_scripts(): void
    {
        $crawler = $this->home();
        $html = (string) $this->client()->getResponse()->getContent();

        self::assertCount(0, $crawler->filter('[style]'), 'No style attributes.');
        self::assertCount(0, $crawler->filter('style'), 'No <style> elements.');
        self::assertCount(0, $crawler->filter('script:not([src]):not([type="application/ld+json"])'), 'No inline scripts.');
        self::assertCount(0, $crawler->filter('script[type="importmap"]'), 'No importmap.');
        self::assertStringNotContainsString('jspm', $html);
        self::assertStringNotContainsString('es-module-shims', $html);
    }

    private ?KernelBrowser $client = null;

    private function home(): Crawler
    {
        $this->client = self::createClient();
        $crawler = $this->client->request('GET', '/eventlokale');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function client(): KernelBrowser
    {
        \assert(null !== $this->client);

        return $this->client;
    }
}
