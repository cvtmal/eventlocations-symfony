<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Entity\Deal;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Tests\Support\Admin\AdminSession;
use App\Venues\ValueObject\Tier;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Verlängerungen" on the dashboard (CRM-8, D22, `Main.dc.html`): won, not renewed, ending within 60 days.
 */
final class RenewalsWidgetTest extends WebTestCase
{
    use AdminSession;
    use ClockSensitiveTrait;
    use Factories;

    public function test_a_row_shows_the_company_tier_end_and_amount_and_links_the_deal(): void
    {
        self::mockTime('2026-11-01 10:00:00 UTC');
        $this->logInAs(AdminRole::Editor);
        $deal = $this->ending('2026-11-30', ['company' => CompanyFactory::createOne(['name' => 'Seehotel Bellerive']), 'tier' => Tier::TopPremium, 'amountRappen' => 360000]);

        $widget = $this->widget();

        self::assertSame('Verlängerungen', $widget->filter('h2')->text());
        self::assertSame('nächste 60 Tage', $widget->filter('.renewal-widget__window')->text());
        self::assertSame('Seehotel Bellerive', $widget->filter('.renewal-widget__company')->text());
        self::assertSame('/admin/crm/deals/'.$deal->id(), $widget->filter('.renewal-widget__company')->attr('href'));
        self::assertSame('Top Premium · endet 30.11.2026', $widget->filter('.crm-list__detail')->text());
        self::assertSame("CHF 3'600", $widget->filter('.renewal-widget__amount')->text());
    }

    public function test_a_deal_without_tier_or_amount_shows_what_it_has(): void
    {
        self::mockTime('2026-11-01 10:00:00 UTC');
        $this->logInAs(AdminRole::Editor);
        $this->ending('2026-11-30');

        $widget = $this->widget();

        self::assertSame('endet 30.11.2026', $widget->filter('.crm-list__detail')->text());
        self::assertCount(0, $widget->filter('.renewal-widget__amount'));
    }

    public function test_the_window_holds_day_0_and_day_60_not_day_61_or_an_ended_contract(): void
    {
        self::mockTime('2026-11-01 10:00:00 UTC');
        $this->logInAs(AdminRole::Editor);
        $this->ending('2026-11-01', ['company' => CompanyFactory::createOne(['name' => 'Tag 0'])]);
        $this->ending('2026-12-31', ['company' => CompanyFactory::createOne(['name' => 'Tag 60'])]);
        $this->ending('2027-01-01', ['company' => CompanyFactory::createOne(['name' => 'Tag 61'])]);
        $this->ending('2026-10-31', ['company' => CompanyFactory::createOne(['name' => 'Beendet'])]);

        self::assertSame(['Tag 0', 'Tag 60'], $this->companies());
    }

    public function test_a_renewed_deal_leaves_the_widget(): void
    {
        self::mockTime('2026-11-01 10:00:00 UTC');
        $this->logInAs(AdminRole::Editor);
        $old = $this->ending('2026-11-30', ['company' => CompanyFactory::createOne(['name' => 'Verlängert'])]);
        DealFactory::createOne(['renewedFrom' => $old, 'company' => $old->company()]);

        self::assertSame([], $this->companies());
    }

    public function test_open_and_lost_deals_have_no_running_contract(): void
    {
        self::mockTime('2026-11-01 10:00:00 UTC');
        $this->logInAs(AdminRole::Editor);
        foreach (['offer', 'lost'] as $stage) {
            DealFactory::new()->inStage($stage)->create(['contractEndOn' => new \DateTimeImmutable('2026-11-30')]);
        }

        self::assertSame([], $this->companies());
        self::assertSame('In den nächsten 60 Tagen endet kein Vertrag.', $this->widget()->filter('.card__body')->text());
    }

    public function test_today_turns_at_midnight_in_zurich(): void
    {
        self::mockTime('2026-10-31 23:30:00 UTC');
        $this->logInAs(AdminRole::Editor);
        $this->ending('2026-10-31', ['company' => CompanyFactory::createOne(['name' => 'Gestern in Zürich'])]);
        $this->ending('2026-11-01', ['company' => CompanyFactory::createOne(['name' => 'Heute in Zürich'])]);

        self::assertSame(['Heute in Zürich'], $this->companies());
    }

    public function test_it_shows_the_soonest_five_of_all(): void
    {
        self::mockTime('2026-11-01 10:00:00 UTC');
        $this->logInAs(AdminRole::Editor);
        foreach ([7, 2, 6, 3, 5, 4] as $day) {
            $this->ending(\sprintf('2026-11-%02d', $day), ['company' => CompanyFactory::createOne(['name' => 'Tag '.$day])]);
        }

        $widget = $this->widget();

        self::assertSame(['Tag 2', 'Tag 3', 'Tag 4', 'Tag 5', 'Tag 6'], $this->companies());
        self::assertSame('5 von 6, nächste 60 Tage', $widget->filter('.renewal-widget__window')->text());
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function ending(string $on, array $attributes = []): Deal
    {
        return DealFactory::new()->inStage('won')->create(['contractEndOn' => new \DateTimeImmutable($on)] + $attributes);
    }

    /**
     * @return list<string>
     */
    private function companies(): array
    {
        return $this->widget()->filter('.renewal-widget__company')->each(static fn (Crawler $link): string => $link->text());
    }

    private function widget(): Crawler
    {
        return $this->client->request('GET', '/admin')->filter('.widget')->eq(4);
    }
}
