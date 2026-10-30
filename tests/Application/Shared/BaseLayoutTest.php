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

    public function test_header_has_menu_search_and_favorites_with_hidden_count_badge(): void
    {
        $crawler = $this->home();

        self::assertSelectorExists('[popovertarget="main-menu"]');
        self::assertSelectorExists('#main-menu[popover]');
        self::assertCount(2, $crawler->filter('.site-header__actions a'));
        self::assertSelectorExists('[data-merkliste-count][hidden]');
    }

    public function test_header_heart_links_to_the_merkliste_and_carries_its_strings(): void
    {
        $crawler = $this->home();

        $heart = $crawler->filter('.site-header__favorites[data-merkliste-link]');
        self::assertSame('/merkliste', $heart->attr('href'));
        self::assertStringContainsString('__count__', (string) $heart->attr('data-count-label'));
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
        self::assertSelectorExists('link[rel="preload"][as="font"][type="font/woff2"][crossorigin][href^="/assets/fonts/sofia-sans-latin-400-normal-"]');
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
        $crawler = $this->client->request('GET', '/hochzeitslocations');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function client(): KernelBrowser
    {
        \assert(null !== $this->client);

        return $this->client;
    }
}
