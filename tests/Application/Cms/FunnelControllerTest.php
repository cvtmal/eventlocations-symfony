<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Factory\RecordedFunnelEventFactory;
use App\Shared\Funnel\FunnelEvent;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The funnel scorecard (G8) at /admin/statistik/funnel: the steps per week and per campaign, the filters, the paying
 * figures and the "n/a" for what G7 will bring. "Now" is Wednesday 2026-11-04 in Zurich.
 */
final class FunnelControllerTest extends WebTestCase
{
    use AdminSession {
        setUp as private startSession;
        tearDown as private endSession;
    }
    use ClockSensitiveTrait;
    use Factories;

    private const string NOW = '2026-11-04T12:00:00+01:00';

    protected function setUp(): void
    {
        $this->startSession();
        self::mockTime(self::NOW);
        $this->connection->executeStatement('DELETE FROM funnel_events');
        $this->connection->executeStatement('DELETE FROM search_misses');
    }

    protected function tearDown(): void
    {
        $this->endSession();
    }

    public function test_the_searches_without_results_render_week_by_week(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->connection->insert('search_misses', ['id' => EntityId::generate(), 'week_start' => '2026-11-02', 'query' => 'canton=zh&guests=900', 'searches' => 3]);
        $this->connection->insert('search_misses', ['id' => EntityId::generate(), 'week_start' => '2026-11-02', 'query' => 'q=Turnhalle', 'searches' => 1]);
        $this->connection->insert('search_misses', ['id' => EntityId::generate(), 'week_start' => '2026-10-26', 'query' => 'q=Scheune', 'searches' => 2]);

        $crawler = $this->client->request('GET', '/admin/statistik/funnel');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('#funnel-misses-table tbody tr');
        self::assertCount(3, $rows);
        self::assertStringContainsString('KW 45: 4 Suchen, 2 verschiedene', $rows->eq(0)->text());
        self::assertStringContainsString('Kanton: ZH · Gäste: 900', $rows->eq(0)->text());
        self::assertSame('/eventlokale?canton=zh&guests=900&staff=1', $rows->eq(0)->filter('a')->attr('href'));
        self::assertStringContainsString('Suchtext: Turnhalle', $rows->eq(1)->text());
        self::assertStringContainsString('KW 44: 2 Suchen, 1 verschiedene', $rows->eq(2)->text());
    }

    public function test_no_search_without_results_shows_the_empty_state(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', '/admin/statistik/funnel');

        self::assertStringContainsString('Im Zeitraum fand jede Suche mindestens einen Eintrag.', $crawler->filter('#funnel-misses-table')->text());
    }

    public function test_the_login_is_required(): void
    {
        $this->client->request('GET', '/admin/statistik/funnel');

        self::assertResponseRedirects('/admin/login');
    }

    public function test_an_editor_sees_the_steps_per_week_and_per_campaign(): void
    {
        $this->logInAs(AdminRole::Editor);
        $one = EntityId::generate();
        $two = EntityId::generate();
        $this->event(FunnelEvent::PreviewOpened, '2026-10-13 09:00', $one, 'zh-brief');
        $this->event(FunnelEvent::PreviewOpened, '2026-10-14 09:00', $two, 'ag-mail');
        $this->event(FunnelEvent::ClaimStarted, '2026-10-20 09:00', $one);
        $this->event(FunnelEvent::VenuePublished, '2026-11-03 09:00', $one);
        $this->event(FunnelEvent::InquiryDelivered, '2026-11-03 10:00', $one);
        $this->event(FunnelEvent::InquiryDelivered, '2026-11-03 11:00', null);
        $this->event(FunnelEvent::ProPaid, '2026-10-21 09:00', $one);
        $this->event(FunnelEvent::BriefSent, '2026-11-02 08:00', null);

        $crawler = $this->client->request('GET', '/admin/statistik/funnel');

        self::assertResponseIsSuccessful();
        self::assertSame('Funnel', $crawler->filter('h1')->text());
        // The steps in funnel order, then the qualified tile, which has no number yet.
        self::assertSame(
            ['Vorschau geöffnet', 'Übernahme gestartet', 'Übernahme bestätigt', 'Veröffentlicht', 'Anfragen zugestellt', 'Pro bezahlt', 'Pro gekündigt', 'Sammelanfragen', 'Qualifizierte Anfragen'],
            $crawler->filter('#funnel-steps .kpi__label')->each(static fn (Crawler $label): string => $label->text()),
        );
        self::assertSame(['2', '1', '0', '1', '2', '1', '0', '1', 'n/a'], $crawler->filter('#funnel-steps .kpi__value')->each(static fn (Crawler $value): string => $value->text()));

        // Twelve weeks, newest first; the venue published and the inquiries of 3 November are in week 45.
        $weeks = $crawler->filter('#funnel-weeks-table tbody tr');
        self::assertCount(12, $weeks);
        self::assertSame(['KW 45 02.11.2026', '0', '0', '0', '1', '2', '0', '0', '1'], self::cells($weeks->first()));
        self::assertSame(['KW 42 12.10.2026', '2', '0', '0', '0', '0', '0', '0', '0'], self::cells($weeks->eq(3)));

        // The campaigns by code, the events without one last; later steps follow the venue's first campaign.
        $campaigns = $crawler->filter('#funnel-campaigns-table tbody tr');
        self::assertCount(3, $campaigns);
        self::assertSame(['ag-mail', '1', '0', '0', '0', '0', '0', '0', '0'], self::cells($campaigns->eq(0)));
        self::assertSame(['zh-brief', '1', '1', '0', '1', '1', '1', '0', '0'], self::cells($campaigns->eq(1)));
        self::assertSame(['Ohne Kampagne', '0', '0', '0', '0', '1', '0', '0', '1'], self::cells($campaigns->eq(2)));

        // One paying venue, and it got a delivered inquiry since it paid; nothing is qualified yet.
        self::assertSame(['1', '1', 'n/a'], $crawler->filter('#funnel-paying-figures .kpi__value')->each(static fn (Crawler $value): string => $value->text()));
        self::assertStringContainsString('100 %', $crawler->filter('#funnel-paying-figures')->text());

        self::assertSame('Statistik', $crawler->filter('.sidebar__item--active')->text());
        self::assertSame('12 Wochen', $crawler->filter('nav.tabs[aria-label="Zeitraum"] a[aria-current="page"]')->text());
        self::assertSame('Alle', $crawler->filter('nav.tabs[aria-label="Kampagne"] a[aria-current="page"]')->text());
        self::assertSame(['Alle', 'ag-mail', 'zh-brief', 'Ohne Kampagne'], $crawler->filter('nav.tabs[aria-label="Kampagne"] a')->each(static fn (Crawler $tab): string => $tab->text()));
    }

    public function test_the_window_and_the_campaign_narrow_the_figures(): void
    {
        $this->logInAs(AdminRole::Editor);
        $one = EntityId::generate();
        $this->event(FunnelEvent::PreviewOpened, '2026-10-13 09:00', $one, 'zh-brief');
        $this->event(FunnelEvent::PreviewOpened, '2026-10-14 09:00', EntityId::generate(), 'ag-mail');
        $this->event(FunnelEvent::VenuePublished, '2026-11-03 09:00', $one);
        $this->event(FunnelEvent::VenuePublished, '2026-11-03 10:00', EntityId::generate());
        // Before the 4-week window: only the 12-week one counts it.
        $this->event(FunnelEvent::PreviewOpened, '2026-09-01 09:00', EntityId::generate(), 'zh-brief');

        $crawler = $this->client->request('GET', '/admin/statistik/funnel?wochen=4&kampagne=zh-brief');

        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('#funnel-weeks-table tbody tr'));
        self::assertSame('4 Wochen', $crawler->filter('nav.tabs[aria-label="Zeitraum"] a[aria-current="page"]')->text());
        self::assertSame('zh-brief', $crawler->filter('nav.tabs[aria-label="Kampagne"] a[aria-current="page"]')->text());
        self::assertSame(['1', '0', '0', '1', '0', '0', '0', '0', 'n/a'], $crawler->filter('#funnel-steps .kpi__value')->each(static fn (Crawler $value): string => $value->text()));
        // The campaign list keeps every campaign of the window, and its links keep the window.
        self::assertSame('/admin/statistik/funnel?wochen=4&kampagne=ag-mail', $crawler->filter('nav.tabs[aria-label="Kampagne"] a')->eq(1)->attr('href'));
        self::assertSame('/admin/statistik/funnel?wochen=12&kampagne=zh-brief', $crawler->filter('nav.tabs[aria-label="Zeitraum"] a')->eq(1)->attr('href'));

        $crawler = $this->client->request('GET', '/admin/statistik/funnel?kampagne=-');
        self::assertSame('Ohne Kampagne', $crawler->filter('nav.tabs[aria-label="Kampagne"] a[aria-current="page"]')->text());
        self::assertSame(['0', '0', '0', '1', '0', '0', '0', '0', 'n/a'], $crawler->filter('#funnel-steps .kpi__value')->each(static fn (Crawler $value): string => $value->text()));
    }

    public function test_unknown_values_fall_back_to_12_weeks_and_all_campaigns(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->event(FunnelEvent::VenuePublished, '2026-11-03 09:00', EntityId::generate());

        foreach (['?wochen=abc&kampagne=a%20b', '?wochen=8&kampagne=max@example.com', '?wochen[]=4&kampagne[]=x', '?wochen=&kampagne='] as $query) {
            $crawler = $this->client->request('GET', '/admin/statistik/funnel'.$query);

            self::assertResponseIsSuccessful($query);
            self::assertCount(12, $crawler->filter('#funnel-weeks-table tbody tr'), $query);
            self::assertSame('Alle', $crawler->filter('nav.tabs[aria-label="Kampagne"] a[aria-current="page"]')->text(), $query);
        }
    }

    public function test_an_empty_log_shows_the_empty_state_and_the_paying_figures(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', '/admin/statistik/funnel');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty__title', 'Im Zeitraum noch keine Ereignisse');
        self::assertCount(0, $crawler->filter('#funnel-weeks-table'));
        self::assertSame(['0', '0', 'n/a'], $crawler->filter('#funnel-paying-figures .kpi__value')->each(static fn (Crawler $value): string => $value->text()));
    }

    public function test_the_statistics_page_links_to_the_funnel(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', '/admin/statistik');

        self::assertSame('/admin/statistik/funnel', $crawler->selectLink('Funnel')->attr('href'));
    }

    /**
     * @return list<string>
     */
    private static function cells(Crawler $row): array
    {
        return $row->filter('td')->each(static fn (Crawler $cell): string => $cell->text());
    }

    private function event(FunnelEvent $event, string $at, ?string $venue, ?string $campaign = null): void
    {
        RecordedFunnelEventFactory::createOne([
            'event' => $event,
            'occurredAt' => new \DateTimeImmutable($at, new \DateTimeZone('Europe/Zurich')),
            'venueId' => $venue,
            'campaignCode' => $campaign,
        ]);
    }
}
