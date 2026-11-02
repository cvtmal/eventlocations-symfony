<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\ReadModel\DealBoardQuery;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Tests\Support\Admin\AdminSession;
use App\Venues\ValueObject\Tier;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The board page (D12, D16): the head's figure, GET filters, the island's props, and its fallback link.
 */
final class DealBoardTest extends WebTestCase
{
    use AdminSession;
    use CountsQueries;
    use Factories;

    private const string BOARD = '/admin/crm/deals';

    public function test_anonymous_is_sent_to_the_login(): void
    {
        $this->client->request('GET', self::BOARD);

        self::assertResponseRedirects('/admin/login');
    }

    public function test_the_head_shows_the_open_figure_without_won_and_lost(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        DealFactory::createOne(['company' => $company, 'amountRappen' => 120000]);
        DealFactory::new()->inStage('offer')->create(['company' => $company, 'amountRappen' => 1120000]);
        DealFactory::new()->inStage('won')->create(['company' => $company, 'amountRappen' => 900000]);

        $crawler = $this->client->request('GET', self::BOARD);

        self::assertResponseIsSuccessful();
        self::assertSame("Offen: CHF 12'400 · 2 Deals", $crawler->filter('[data-deal-open]')->text());
    }

    public function test_one_open_deal_is_singular(): void
    {
        $this->logInAs(AdminRole::Editor);
        DealFactory::createOne(['amountRappen' => 100050]);

        $crawler = $this->client->request('GET', self::BOARD);

        self::assertSame("Offen: CHF 1'000.50 · 1 Deal", $crawler->filter('[data-deal-open]')->text());
    }

    public function test_the_island_gets_columns_cards_and_labels(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Editor, 'Andrea Keller');
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events']);
        $deal = DealFactory::new()->inStage('offer')->create(['name' => 'Premium 2027', 'company' => $company, 'tier' => Tier::Premium, 'amountRappen' => 240000, 'expectedCloseOn' => new \DateTimeImmutable('2026-11-15'), 'owner' => $owner]);

        $props = $this->props($this->client->request('GET', self::BOARD));

        self::assertIsArray($props['columns']);
        self::assertCount(6, $props['columns']);
        $column = $props['columns'][2];
        self::assertSame(['offer', 'Angebot', false, false, 1, 240000], [$column['code'], $column['label'], $column['isWon'], $column['isLost'], $column['count'], $column['sumRappen']]);
        self::assertSame([true, false], [$props['columns'][4]['isWon'], $props['columns'][4]['isLost']]);
        self::assertSame('/admin/crm/deals?ansicht=liste&stage=offer', $column['moreHref']);
        self::assertSame([
            'id' => $deal->id(),
            'name' => 'Premium 2027',
            'company' => 'Seeblick Events',
            'href' => '/admin/crm/deals/'.$deal->id(),
            'stageUrl' => '/admin/api/crm/deals/'.$deal->id().'/stage',
            'tier' => ['code' => 'premium', 'label' => 'Premium'],
            'amountRappen' => 240000,
            'expectedCloseOn' => '2026-11-15',
            'closedOn' => null,
            'lostReason' => null,
            'ownerName' => 'Andrea Keller',
            'ownerInitials' => 'AK',
        ], $column['cards'][0]);
        self::assertSame('budget', $props['lostReasons'][0]['value']);
        self::assertSame('Offen: CHF %sum · %n Deals', $props['labels']['openOther']);
        self::assertSame('Offen: CHF %sum · 1 Deal', $props['labels']['openOne']);
        self::assertSame('Weiter: %deal nach %stage verschieben', $props['labels']['nextTo']);
        foreach ($props['labels'] as $name => $label) {
            self::assertStringStartsNotWith('deal.board.', $label, $name.' is translated.');
        }
    }

    public function test_filters_are_get_parameters_for_the_cards_and_the_totals(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        DealFactory::createOne(['company' => $company, 'name' => 'Treffer', 'owner' => $owner, 'amountRappen' => 100, 'expectedCloseOn' => new \DateTimeImmutable('2026-11-15')]);
        DealFactory::createOne(['company' => $company, 'name' => 'Fremd', 'owner' => AdminUserFactory::createOne(), 'amountRappen' => 900, 'expectedCloseOn' => new \DateTimeImmutable('2026-11-15')]);

        $crawler = $this->client->request('GET', self::BOARD.'?owner='.$owner->id().'&abschluss=2026-11');

        self::assertResponseIsSuccessful();
        $props = $this->props($crawler);
        self::assertSame(['Treffer'], array_column($props['columns'][0]['cards'], 'name'));
        self::assertSame('/admin/crm/deals?ansicht=liste&stage=new&owner='.$owner->id().'&abschluss=2026-11', $props['columns'][0]['moreHref']);
        self::assertSame('Offen: CHF 1 · 1 Deal', $crawler->filter('[data-deal-open]')->text());
        self::assertSame($owner->id(), $crawler->filter('.deal-header__filters select[name="owner"] option[selected]')->attr('value'));
        self::assertSame('November 2026', $crawler->filter('.deal-header__filters select[name="abschluss"] option[selected]')->text());
        self::assertSame('/admin/crm/deals?owner='.$owner->id().'&abschluss=2026-11&ansicht=liste', $crawler->filter('.seg .seg__item')->eq(1)->attr('href'));
    }

    public function test_an_invalid_filter_shows_the_unfiltered_board_at_422(): void
    {
        $this->logInAs(AdminRole::Editor);
        DealFactory::createOne(['name' => 'Premium 2027']);

        $crawler = $this->client->request('GET', self::BOARD.'?owner=bogus');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Premium 2027'], array_column($this->props($crawler)['columns'][0]['cards'], 'name'));
        self::assertSelectorTextContains('.form-error', 'Diese Filter sind ungültig');
    }

    public function test_without_javascript_the_board_links_to_the_list(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', self::BOARD);

        self::assertSame('/admin/crm/deals?ansicht=liste', $crawler->filter('[data-island="deal-board"] a')->attr('href'));
    }

    public function test_a_capped_column_keeps_its_true_count(): void
    {
        $this->logInAs(AdminRole::Editor);
        DealFactory::createMany(DealBoardQuery::OPEN_CAP + 1, ['company' => CompanyFactory::createOne()]);

        $props = $this->props($this->client->request('GET', self::BOARD));

        self::assertCount(DealBoardQuery::OPEN_CAP, $props['columns'][0]['cards']);
        self::assertSame(DealBoardQuery::OPEN_CAP + 1, $props['columns'][0]['count']);
    }

    public function test_the_board_stays_within_seven_queries_with_the_user_refresh(): void
    {
        $this->logInAs(AdminRole::Editor);
        DealFactory::createMany(3, ['company' => CompanyFactory::createOne()]);

        // Owners with the badge, stages, months, cards, totals, the access-request badge; the refresh makes seven in
        // production, D16's 7.
        self::assertSame(6, $this->queriesOf(self::BOARD));
    }

    /**
     * @return array<string, mixed>
     */
    private function props(Crawler $crawler): array
    {
        /** @var array<string, mixed> $props */
        $props = json_decode((string) $crawler->filter('[data-island="deal-board"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);

        return $props;
    }
}
