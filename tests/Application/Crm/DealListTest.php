<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Tests\Support\Admin\AdminSession;
use App\Venues\ValueObject\Tier;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The deal list (D12): columns, search, filters, sort, paging, and an invalid filter as the unfiltered list at 422.
 */
final class DealListTest extends WebTestCase
{
    use AdminSession;
    use CountsQueries;
    use Factories;

    private const string LIST = '/admin/crm/deals?ansicht=liste';

    public function test_anonymous_is_sent_to_the_login(): void
    {
        $this->client->request('GET', self::LIST);

        self::assertResponseRedirects('/admin/login');
    }

    public function test_an_editor_sees_every_deal_with_its_columns(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Editor, 'Andrea Keller');
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events']);
        $deal = DealFactory::new()->inStage('offer')->create([
            'name' => 'Premium 2027',
            'company' => $company,
            'tier' => Tier::Premium,
            'amountRappen' => 240050,
            'expectedCloseOn' => new \DateTimeImmutable('2026-11-15'),
            'owner' => $owner,
        ]);

        $crawler = $this->client->request('GET', self::LIST);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Deals 1');
        $row = $crawler->filter('#deal-list tbody tr')->first();
        self::assertSame('/admin/crm/deals/'.$deal->id(), $row->filter('.crm-list__link')->attr('href'));
        self::assertSame('/admin/crm/firmen/'.$company->id(), $row->filter('.crm-list__detail')->attr('href'));
        self::assertStringContainsString('Seeblick Events', $row->text());
        self::assertStringContainsString('Angebot', $row->text());
        self::assertStringContainsString('Premium', $row->filter('td')->eq(2)->text());
        self::assertSame("CHF 2'400.50", $row->filter('td')->eq(3)->text());
        self::assertSame('15.11.2026', $row->filter('td')->eq(4)->text());
        self::assertSame('Andrea Keller', $row->filter('td')->eq(5)->text());
    }

    public function test_it_searches_the_deal_and_the_company(): void
    {
        $this->logInAs(AdminRole::Editor);
        DealFactory::createOne(['name' => 'Premium 2027', 'company' => CompanyFactory::createOne(['name' => 'Alpenhof'])]);
        DealFactory::createOne(['name' => 'Basic 2027', 'company' => CompanyFactory::createOne(['name' => 'Rössli Züri'])]);

        self::assertSame(['Premium 2027'], $this->names($this->client->request('GET', self::LIST.'&q=premium')));
        self::assertSame(['Basic 2027'], $this->names($this->client->request('GET', self::LIST.'&q=rossli')));
        self::assertSame([], $this->names($this->client->request('GET', self::LIST.'&q=!!!')));
    }

    public function test_it_filters_by_stage_owner_and_month(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Editor);
        DealFactory::new()->inStage('offer')->create(['name' => 'Offerte', 'owner' => $owner]);
        DealFactory::createOne(['name' => 'Ohne Zuständige', 'expectedCloseOn' => new \DateTimeImmutable('2026-12-01')]);
        DealFactory::createOne(['name' => 'Fremd', 'owner' => AdminUserFactory::createOne(), 'expectedCloseOn' => new \DateTimeImmutable('2027-01-31')]);

        self::assertSame(['Offerte'], $this->names($this->client->request('GET', self::LIST.'&stage=offer')));
        self::assertSame(['Offerte'], $this->names($this->client->request('GET', self::LIST.'&owner='.$owner->id())));
        self::assertSame(['Offerte'], $this->names($this->client->request('GET', self::LIST.'&stage=offer&owner='.$owner->id())));
        self::assertSame(['Ohne Zuständige'], $this->names($this->client->request('GET', self::LIST.'&owner=none')));
        $crawler = $this->client->request('GET', self::LIST.'&abschluss=2027-01');
        self::assertSame(['Fremd'], $this->names($crawler));
        self::assertStringContainsString('Abschluss: Januar 2027', $crawler->filter('.filter-bar')->text());
    }

    public function test_a_saved_month_stays_valid_when_no_deal_is_left_in_it(): void
    {
        $this->logInAs(AdminRole::Editor);
        DealFactory::createOne(['expectedCloseOn' => new \DateTimeImmutable('2026-12-01')]);

        $crawler = $this->client->request('GET', self::LIST.'&abschluss=2026-05');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->names($crawler));
    }

    public function test_it_sorts_with_empty_values_last(): void
    {
        $this->logInAs(AdminRole::Editor);
        DealFactory::createOne(['name' => 'Klein', 'amountRappen' => 100, 'expectedCloseOn' => new \DateTimeImmutable('2027-02-01')]);
        DealFactory::createOne(['name' => 'Gross', 'amountRappen' => 900, 'expectedCloseOn' => new \DateTimeImmutable('2026-12-01')]);
        DealFactory::createOne(['name' => 'Offen']);

        self::assertSame(['Gross', 'Klein', 'Offen'], $this->names($this->client->request('GET', self::LIST)));
        self::assertSame(['Gross', 'Klein', 'Offen'], $this->names($this->client->request('GET', self::LIST.'&sort=amount&dir=desc')));
        self::assertSame(['Klein', 'Gross', 'Offen'], $this->names($this->client->request('GET', self::LIST.'&sort=amount&dir=asc')));
    }

    public function test_it_pages_by_fifty(): void
    {
        $this->logInAs(AdminRole::Editor);
        DealFactory::createMany(51);

        self::assertCount(50, $this->client->request('GET', self::LIST)->filter('#deal-list tbody tr'));
        self::assertCount(1, $this->client->request('GET', self::LIST.'&page=2')->filter('#deal-list tbody tr'));
    }

    public function test_an_invalid_filter_shows_the_unfiltered_list_at_422(): void
    {
        $this->logInAs(AdminRole::Editor);
        DealFactory::createOne(['name' => 'Premium 2027']);

        $crawler = $this->client->request('GET', self::LIST.'&stage=bogus');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Premium 2027'], $this->names($crawler));
        self::assertSelectorTextContains('.form-error', 'Diese Filter sind ungültig');
    }

    public function test_the_list_runs_at_most_six_queries_with_the_user_refresh(): void
    {
        $this->logInAs(AdminRole::Editor);
        DealFactory::createMany(3, ['company' => CompanyFactory::createOne()]);

        // Owners with the badge, stages, months, COUNT, rows; the user refresh makes six in production (N14).
        self::assertSame(5, $this->queriesOf(self::LIST));
    }

    public function test_the_view_switch_keeps_the_filter(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', self::LIST.'&owner='.$owner->id());

        $links = $crawler->filter('.seg .seg__item');
        self::assertSame('/admin/crm/deals?owner='.$owner->id(), $links->eq(0)->attr('href'));
        self::assertSame('/admin/crm/deals?owner='.$owner->id().'&ansicht=liste', $links->eq(1)->attr('href'));
        self::assertSame('page', $links->eq(1)->attr('aria-current'));
    }

    /**
     * @return list<string>
     */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('#deal-list .crm-list__link')->each(static fn (Crawler $link): string => $link->text());
    }
}
