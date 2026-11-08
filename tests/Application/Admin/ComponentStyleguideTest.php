<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * `/admin/_styleguide` renders every kit component (#23), and the markup stays clear of what #16's CSP forbids.
 */
final class ComponentStyleguideTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    /**
     * @return iterable<string, array{string}>
     */
    public static function components(): iterable
    {
        $names = [
            'button', 'pill', 'tier-badge', 'avatar', 'card', 'table', 'pagination', 'filter-bar', 'tabs', 'record-tabs', 'record-header', 'property',
            'modal', 'drawer', 'dropdown', 'toast', 'combobox', 'date-input', 'money-input', 'markdown', 'dropzone', 'sortable', 'kanban', 'timeline',
            'empty', 'skeleton', 'kpi', 'bar-chart', 'chips', 'seg', 'progress', 'form', 'form-errors', 'locale-tabs',
        ];

        foreach ($names as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('components')]
    public function test_every_component_has_a_section(string $component): void
    {
        $this->logInAs();

        $this->client->request('GET', '/admin/_styleguide');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(\sprintf('[data-component="%s"]', $component));
    }

    public function test_the_page_holds_nothing_a_strict_csp_forbids(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin/_styleguide');

        self::assertCount(0, $crawler->filter('[style]'));
        self::assertCount(0, $crawler->filter('style'));
        self::assertCount(0, $crawler->filter('script:not([src])'));
        self::assertCount(0, $crawler->filter('script:not([type="module"])'));
        foreach ($crawler->filter('script') as $script) {
            \assert($script instanceof \DOMElement);
            self::assertStringStartsWith('/', $script->getAttribute('src'));
            self::assertStringStartsNotWith('//', $script->getAttribute('src'));
        }
    }

    public function test_the_palette_is_deferred_and_the_sprite_url_is_there(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin/_styleguide');

        self::assertStringStartsWith('/admin-build/', (string) $crawler->filter('[data-island="command-palette"]')->attr('data-island-defer'));
        self::assertStringContainsString('admin-sprite', (string) $crawler->filter('meta[name="admin-sprite"]')->attr('content'));
        self::assertCount(1, $crawler->filter('button[data-palette-open]'));
    }

    public function test_the_locale_tabs_have_both_shapes(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin/_styleguide');
        $section = $crawler->filter('[data-component="locale-tabs"]');

        self::assertCount(2, $section->filter('[role="tablist"]'));
        self::assertCount(4, $section->filter('[role="tab"]'));
        self::assertCount(5, $section->filter('.locale-tabs__panel'));
        self::assertCount(4, $section->filter('[role="tabpanel"]'));
        // `active` (#24) opens another locale than the first, e.g. the first one with an error after a 422.
        self::assertSame('true', $section->filter('#sg-locale-two-tab-de')->attr('aria-selected'));
        self::assertSame('false', $section->filter('#sg-locale-active-tab-de')->attr('aria-selected'));
        self::assertSame('-1', $section->filter('#sg-locale-active-tab-de')->attr('tabindex'));
        self::assertSame('true', $section->filter('#sg-locale-active-tab-fr')->attr('aria-selected'));
        self::assertNull($section->filter('#sg-locale-active-tab-fr')->attr('tabindex'));
    }

    public function test_the_tier_badge_shows_a_grey_free_and_a_green_pro_plan(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin/_styleguide');

        $pills = $crawler->filter('[data-component="tier-badge"] .pill');
        self::assertSame(['Gratis', 'Pro'], $pills->each(static fn (Crawler $pill): string => $pill->text()));
        self::assertSame(['pill pill--grey', 'pill pill--green'], $pills->each(static fn (Crawler $pill): string => (string) $pill->attr('class')));
    }

    public function test_the_record_header_has_an_optional_media_slot(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin/_styleguide');

        self::assertCount(1, $crawler->filter('[data-component="record-header"] .record-header__media'));
    }

    public function test_an_empty_table_is_one_row_across_all_columns(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin/_styleguide');

        $cell = $crawler->filter('[data-component="table"] td.table__empty');
        self::assertCount(1, $cell);
        self::assertSame('2', $cell->attr('colspan'));
        self::assertCount(1, $cell->filter('.empty'));
    }

    public function test_the_bar_chart_scales_its_bars_and_repeats_the_figures_in_a_hidden_table(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin/_styleguide');

        $chart = $crawler->filter('[data-component="bar-chart"] figure.bar-chart');
        self::assertSame('0 0 70 100', $chart->filter('svg')->attr('viewBox'));
        self::assertSame('true', $chart->filter('svg')->attr('aria-hidden'));
        // The tallest bar (12) fills the height, a 0 has none, a 3 is a quarter.
        self::assertSame(['25', '58.33', '33.33', '0', '75', '100', '50'], $chart->filter('rect')->each(static fn ($bar): string => (string) $bar->attr('height')));
        self::assertSame('1. November: 3', $chart->filter('rect title')->first()->text());
        self::assertSame(['1. November', '3'], $chart->filter('table.visually-hidden tbody tr')->first()->filter('th, td')->each(static fn ($cell): string => $cell->text()));
        self::assertCount(7, $chart->filter('table.visually-hidden tbody tr'));
        self::assertCount(0, $chart->filter('[style]'));
    }

    public function test_a_sort_header_is_a_link_with_its_state_on_the_cell(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin/_styleguide?q=see&page=3');

        $sorted = $crawler->filter('[data-component="table"] th[aria-sort]');
        self::assertCount(1, $sorted);
        self::assertSame('ascending', $sorted->attr('aria-sort'));
        self::assertSame('/admin/_styleguide?q=see&sort=name&dir=desc', $sorted->filter('a')->attr('href'));
        self::assertSame('/admin/_styleguide?q=see&sort=town&dir=asc', $crawler->filter('[data-component="table"] th:not([aria-sort]) a')->attr('href'));
    }

    public function test_the_pagination_keeps_the_query_and_marks_the_page(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin/_styleguide?q=see');
        $pagination = $crawler->filter('[data-component="table"] .pagination');

        self::assertStringContainsString('101 – 150 von 574 Resultaten', $pagination->text());
        self::assertSame('3', $pagination->filter('[aria-current="page"]')->text());
        self::assertSame('/admin/_styleguide?q=see&page=2', $pagination->filter('[rel="prev"]')->attr('href'));
        self::assertSame('/admin/_styleguide?q=see&page=4', $pagination->filter('[rel="next"]')->attr('href'));
        self::assertSame('/admin/_styleguide?q=see', $pagination->filter('.pagination__pages .btn--icon')->first()->attr('href'));
    }

    public function test_the_invalid_copy_shows_its_errors(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin/_styleguide');
        $section = $crawler->filter('[data-component="form-errors"]');

        self::assertSame('Bitte prüfe die markierten Felder.', $section->filter('.form-error[role="alert"]')->text());
        self::assertSame('true', $section->filter('#sample_invalid_name')->attr('aria-invalid'));
    }

    public function test_the_boundary_buttons_of_a_server_list_stay_focusable_and_send_nothing(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin/_styleguide');
        $items = $crawler->filter('[data-component="sortable"] ol.sortable > li');

        self::assertSame('true', $items->first()->filter('button')->first()->attr('aria-disabled'));
        self::assertSame('button', $items->first()->filter('button')->first()->attr('type'));
        self::assertNull($items->first()->filter('button')->last()->attr('aria-disabled'));
        self::assertSame('submit', $items->first()->filter('button')->last()->attr('type'));
        self::assertSame('true', $items->last()->filter('button')->last()->attr('aria-disabled'));
    }
}
