<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Entity\Deal;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\Tier;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Tier anwenden" through HTTP (CRM-8, D20): the record's action and form, the form the board's prompt posts, its
 * tokens, and what a stale or spent offer says.
 */
final class ApplyDealTierTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ForgedPosts;

    public function test_anonymous_is_sent_to_the_login(): void
    {
        ['deal' => $deal] = $this->won();

        $this->post($deal);

        self::assertResponseRedirects('/admin/login');
    }

    public function test_the_record_offers_the_tier_in_its_menu_and_applying_it_ends_the_offer(): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal, 'venue' => $venue] = $this->won();

        $crawler = $this->client->request('GET', '/admin/crm/deals/'.$deal->id());

        self::assertResponseIsSuccessful();
        self::assertSame('Tier anwenden', $crawler->filter('[commandfor="deal-tier"]')->text());
        self::assertSame('«Schloss Seeblick» hat heute den Tier Standard. Der Deal verkauft Premium.', $crawler->filter('#deal-tier p')->text());

        $this->client->submit($crawler->filter('#deal-tier-form')->form(), [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id(), 303);
        self::assertSame('premium', $this->tierOf($venue));
        self::assertSame(1, $this->auditRows('venue', (string) $venue->number()));
        self::assertSame(1, $this->auditRows('deal', $deal->id()));
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(1, $this->purger()->listings);

        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('.toast', 'Der Tier gilt jetzt auf der Location.');
        self::assertCount(0, $crawler->filter('[commandfor="deal-tier"]'));
        self::assertCount(0, $crawler->filter('#deal-tier'));
    }

    public function test_a_deal_that_is_not_won_has_no_action(): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal] = $this->won('offer');

        $crawler = $this->client->request('GET', '/admin/crm/deals/'.$deal->id());

        self::assertCount(0, $crawler->filter('[commandfor="deal-tier"]'));
    }

    public function test_the_board_prompt_comes_back_to_the_board(): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal, 'venue' => $venue] = $this->won();

        $this->post($deal, ['return_to' => '/admin/crm/deals?owner=keller']);

        self::assertResponseRedirects('/admin/crm/deals?owner=keller', 303);
        self::assertSame('premium', $this->tierOf($venue));
    }

    public function test_a_foreign_return_address_goes_to_the_record(): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal] = $this->won();

        $this->post($deal, ['return_to' => 'https://example.invalid/admin/']);

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id(), 303);
    }

    public function test_a_stale_prompt_changes_nothing_and_says_so(): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal, 'venue' => $venue] = $this->won();

        $this->post($deal, ['expected' => 'empfehlung']);

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id(), 303);
        self::assertSame('basic', $this->tierOf($venue));
        self::assertSame(0, $this->auditRows('deal', $deal->id()));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'wurde inzwischen geändert');
    }

    public function test_a_second_click_says_the_offer_is_gone_without_a_row(): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal, 'venue' => $venue] = $this->won();

        $this->post($deal);
        $this->client->followRedirect();
        $this->post($deal);

        self::assertSame(1, $this->auditRows('deal', $deal->id()));
        self::assertSame(1, $this->auditRows('venue', (string) $venue->number()));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'keinen Tier mehr');
    }

    public function test_an_unknown_deal_is_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('POST', '/admin/crm/deals/019a0000-0000-7000-8000-000000000000/tier', ['_token' => 'csrf-token', 'expected' => 'basic'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_an_expected_tier_that_does_not_exist_changes_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal, 'venue' => $venue] = $this->won();

        $this->post($deal, ['expected' => 'gold']);

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id(), 303);
        self::assertSame('basic', $this->tierOf($venue));
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $server
     */
    #[DataProvider('forgedPosts')]
    public function test_a_forged_post_changes_nothing(array $fields, array $server): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal, 'venue' => $venue] = $this->won();

        $this->client->request('POST', '/admin/crm/deals/'.$deal->id().'/tier', $fields + ['expected' => 'basic'], server: $server);

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id(), 303);
        self::assertSame('basic', $this->tierOf($venue));
    }

    /**
     * @return array{deal: Deal, venue: Venue}
     */
    private function won(string $stage = 'won'): array
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick', 'tier' => Tier::Basic]);
        $company = CompanyFactory::new()->withVenues($venue)->create();

        return [
            'deal' => DealFactory::new()->inStage($stage)->create(['company' => $company, 'venue' => $venue, 'tier' => Tier::Premium]),
            'venue' => $venue,
        ];
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(Deal $deal, array $fields = []): void
    {
        $this->client->request('POST', '/admin/crm/deals/'.$deal->id().'/tier', $fields + ['_token' => 'csrf-token', 'expected' => 'basic'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
    }

    private function tierOf(Venue $venue): string
    {
        return (string) $this->connection->fetchOne('SELECT tier FROM venues WHERE number = ?', [$venue->number()]);
    }

    private function auditRows(string $type, string $id): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log WHERE subject_type = ? AND subject_id = ?', [$type, $id]);
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }
}
