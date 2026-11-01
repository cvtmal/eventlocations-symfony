<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Entity\Deal;
use App\Factory\DealFactory;
use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Verlängern" through HTTP (CRM-8, D21): the record's action, PRG to the new deal, the double click and the tokens.
 */
final class DealRenewTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ForgedPosts;

    public function test_anonymous_is_sent_to_the_login(): void
    {
        $this->post($this->won());

        self::assertResponseRedirects('/admin/login');
    }

    public function test_the_record_offers_it_and_the_form_leads_to_the_new_deal(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = $this->won();

        $crawler = $this->client->request('GET', '/admin/crm/deals/'.$deal->id());

        self::assertSame('Verlängern', $crawler->filter('[commandfor="deal-renew"]')->text());

        $this->client->submit($crawler->filter('#deal-renew-form')->form(), [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseStatusCodeSame(303);
        $new = $this->connection->fetchOne('SELECT id FROM crm_deals WHERE renewed_from_id = ?', [$deal->id()]);
        self::assertResponseRedirects('/admin/crm/deals/'.$new, 303);

        $this->client->followRedirect();

        self::assertSelectorTextContains('h1', 'Verlängerung 2027');
        self::assertSelectorTextContains('.toast', 'Verlängerung angelegt.');
    }

    public function test_a_renewed_deal_no_longer_offers_it(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = $this->won();
        $this->post($deal);

        $crawler = $this->client->request('GET', '/admin/crm/deals/'.$deal->id());

        self::assertCount(0, $crawler->filter('[commandfor="deal-renew"]'));
        self::assertCount(0, $crawler->filter('#deal-renew'));
    }

    public function test_an_open_deal_or_one_without_an_end_has_no_action(): void
    {
        $this->logInAs(AdminRole::Editor);

        foreach ([
            DealFactory::new()->inStage('offer')->create(['contractEndOn' => new \DateTimeImmutable('2026-12-31')]),
            DealFactory::new()->inStage('won')->create(),
        ] as $deal) {
            self::assertCount(0, $this->client->request('GET', '/admin/crm/deals/'.$deal->id())->filter('[commandfor="deal-renew"]'));
        }
    }

    public function test_a_double_click_leaves_one_renewal_and_says_so(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = $this->won();
        $this->post($deal);
        $this->client->followRedirect();

        $this->post($deal);

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id(), 303);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_deals WHERE renewed_from_id = ?', [$deal->id()]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'lässt sich nicht verlängern');
    }

    public function test_an_unknown_deal_is_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('POST', '/admin/crm/deals/019a0000-0000-7000-8000-000000000000/verlaengern', ['_token' => 'csrf-token'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $server
     */
    #[DataProvider('forgedPosts')]
    public function test_a_forged_post_changes_nothing(array $fields, array $server): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = $this->won();

        $this->client->request('POST', '/admin/crm/deals/'.$deal->id().'/verlaengern', $fields, server: $server);

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id(), 303);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_deals WHERE renewed_from_id = ?', [$deal->id()]));
    }

    private function won(): Deal
    {
        return DealFactory::new()->inStage('won')->create([
            'contractStartOn' => new \DateTimeImmutable('2026-01-01'),
            'contractEndOn' => new \DateTimeImmutable('2026-12-31'),
        ]);
    }

    private function post(Deal $deal): void
    {
        $this->client->request('POST', '/admin/crm/deals/'.$deal->id().'/verlaengern', ['_token' => 'csrf-token'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
    }
}
