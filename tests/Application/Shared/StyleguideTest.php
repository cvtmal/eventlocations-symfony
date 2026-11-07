<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * `/_styleguide` (#1, dev and test only) renders every component with its no-JS hooks.
 */
final class StyleguideTest extends WebTestCase
{
    private ?KernelBrowser $client = null;

    /**
     * @return iterable<string, array{string}>
     */
    public static function components(): iterable
    {
        foreach (['colors', 'typography', 'spacing', 'radii-shadows', 'shadow', 'icons', 'buttons', 'button-states', 'round-buttons', 'merken', 'chips', 'tiles', 'badge', 'tag', 'text-input', 'field-states', 'breadcrumb', 'overlays', 'dialog', 'carousel', 'toast', 'focus-ring', 'map', 'tooltip', 'count-badge', 'brand', 'photo-placeholder', 'extras'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('components')]
    public function test_styleguide_renders_every_component_section(string $component): void
    {
        $this->styleguide();

        self::assertSelectorExists(\sprintf('[data-component="%s"]', $component));
    }

    public function test_styleguide_has_no_inline_styles_or_scripts(): void
    {
        $crawler = $this->styleguide();
        $html = (string) $this->client()->getResponse()->getContent();

        self::assertCount(0, $crawler->filter('[style]'), 'No style attributes.');
        self::assertCount(0, $crawler->filter('style'), 'No <style> elements.');
        self::assertCount(0, $crawler->filter('script:not([src]):not([type="application/ld+json"])'), 'No inline scripts.');
        self::assertCount(0, $crawler->filter('script[type="importmap"]'), 'No importmap.');
        self::assertStringNotContainsString('jspm', $html);
        self::assertStringNotContainsString('es-module-shims', $html);
    }

    public function test_every_script_is_a_module_from_the_asset_mapper(): void
    {
        $crawler = $this->styleguide();

        $scripts = $crawler->filter('script:not([type="application/ld+json"])');
        self::assertGreaterThan(0, $scripts->count());
        foreach ($scripts as $script) {
            \assert($script instanceof \DOMElement);
            self::assertSame('module', $script->getAttribute('type'));
            self::assertStringStartsWith('/assets/js/', $script->getAttribute('src'));
        }
    }

    public function test_every_icon_reference_points_to_an_existing_sprite_symbol(): void
    {
        $crawler = $this->styleguide();
        $ids = self::spriteIds();

        // The logo's `<use href="#logo-art">` points at the header's copy in the same page, not at the sprite.
        $uses = $crawler->filter('use[href]:not([href="#logo-art"])');
        self::assertGreaterThan(36, $uses->count());
        foreach ($uses as $use) {
            \assert($use instanceof \DOMElement);
            [$path, $fragment] = explode('#', $use->getAttribute('href'), 2) + ['', ''];
            self::assertStringStartsWith('/assets/icons/sprite-', $path);
            self::assertContains($fragment, $ids, \sprintf('Icon "%s" is not a symbol in assets/icons/sprite.svg.', $fragment));
        }
    }

    public function test_overlays_open_without_javascript(): void
    {
        $crawler = $this->styleguide();

        $overlays = $crawler->filter('[data-overlay]');
        // The three filter overlays plus #main-menu, an overlay since #7 (#1 F-m2: focus wrap).
        self::assertCount(4, $overlays);
        foreach ($overlays as $overlay) {
            \assert($overlay instanceof \DOMElement);
            $id = $overlay->getAttribute('id');
            self::assertTrue($overlay->hasAttribute('popover'));
            self::assertSelectorExists(\sprintf('[popovertarget="%s"]:not([popovertargetaction])', $id));
            self::assertSelectorExists(\sprintf('#%s [popovertarget="%s"][popovertargetaction="hide"]', $id, $id));
        }
    }

    public function test_the_boards_open_panels_are_drawings_without_a_hook(): void
    {
        $crawler = $this->styleguide();

        $drawings = $crawler->filter('[inert]');
        self::assertCount(2, $drawings);
        self::assertCount(1, $crawler->filter('[data-component="dialog"] [inert]'));
        self::assertCount(1, $crawler->filter('[data-component="overlays"] [inert]'));
        self::assertCount(2, $crawler->filter('[data-component="overlays"] .sg-overlay'));
        self::assertCount(0, $crawler->filter('[inert] :is([data-overlay], [data-dialog], [popover], [popovertarget], [commandfor], dialog)'));
    }

    public function test_every_id_is_unique(): void
    {
        $crawler = $this->styleguide();

        $ids = $crawler->filter('[id]')->each(static fn (Crawler $node): string => (string) $node->attr('id'));

        self::assertSame([], array_values(array_diff_key($ids, array_unique($ids))));
    }

    public function test_colours_sit_in_the_boards_five_groups(): void
    {
        $crawler = $this->styleguide();

        self::assertCount(5, $crawler->filter('[data-component="colors"] .sg-color-group'));
        self::assertCount(24, $crawler->filter('[data-component="colors"] .sg-color'));
    }

    public function test_map_has_the_boards_legend(): void
    {
        $crawler = $this->styleguide();

        self::assertCount(5, $crawler->filter('.sg-legend li'));
    }

    public function test_dialog_is_operable_without_javascript(): void
    {
        $this->styleguide();

        self::assertSelectorExists('dialog[data-dialog]');
        self::assertSelectorExists('[commandfor][command="show-modal"]');
        self::assertSelectorExists('dialog[data-dialog] form[method="dialog"]');
    }

    public function test_carousel_controls_are_hidden_without_javascript_and_track_is_focusable(): void
    {
        $this->styleguide();

        self::assertSelectorExists('[data-carousel-controls][hidden]');
        self::assertSelectorExists('.carousel__track[tabindex="0"]');
        self::assertSelectorExists('section.carousel[aria-label]');
    }

    public function test_canton_map_slot_renders_when_geo_is_installed(): void
    {
        if (!is_file(\dirname(__DIR__, 3).'/templates/styleguide/_canton_map.html.twig')) {
            self::markTestSkipped('The canton map slot is filled by #3 (geo-reference-data), which is not in yet.');
        }

        $crawler = $this->styleguide();

        self::assertCount(26, $crawler->filter('[id^="ch-"]'));
    }

    private function styleguide(): Crawler
    {
        $this->client = self::createClient();
        $crawler = $this->client->request('GET', '/_styleguide');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function client(): KernelBrowser
    {
        \assert(null !== $this->client);

        return $this->client;
    }

    /**
     * @return list<string>
     */
    private static function spriteIds(): array
    {
        $dom = new \DOMDocument();
        $dom->load(\dirname(__DIR__, 3).'/assets/icons/sprite.svg');
        $ids = [];
        foreach ($dom->getElementsByTagName('symbol') as $symbol) {
            $ids[] = $symbol->getAttribute('id');
        }

        return $ids;
    }
}
