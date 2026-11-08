<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Entity\Deal;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Factory\SubscriptionFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\AdminSession;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * «Zahlung stornieren» through HTTP (G9 finish, D42): the record offers it for the venue's latest term while it runs,
 * voiding drops the venue to Free, takes back `pro_paid` and is audited, and a stale or forged post changes nothing.
 */
final class DealPaymentVoidTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ForgedPosts;

    public function test_anonymous_is_sent_to_the_login(): void
    {
        ['deal' => $deal] = $this->paid();

        $this->post($deal, '019a0000-0000-7000-8000-000000000000');

        self::assertResponseRedirects('/admin/login');
    }

    public function test_a_recorded_payment_can_be_voided_and_the_venue_drops_to_free(): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal, 'venue' => $venue] = $this->paid();
        self::assertSame(KnownTiers::PRO, $this->tierOf($venue));
        self::assertSame(1, $this->proPaid($venue));

        $crawler = $this->client->request('GET', '/admin/crm/deals/'.$deal->id());
        self::assertSame('Zahlung stornieren', $crawler->filter('[commandfor="deal-void"]')->text());
        self::assertStringContainsString('Tarif Pro von «Schloss Seeblick»', $crawler->filter('#deal-void p')->text());
        $this->client->submit($crawler->filter('#deal-void-form')->form(), [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id(), 303);
        self::assertSame(KnownTiers::FREE, $this->tierOf($venue));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM subscriptions WHERE venue_id = ?', [$venue->id()]));
        self::assertSame(0, $this->proPaid($venue), 'D18: a voided payment is no paying venue');
        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT count(*) FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ? AND command LIKE '%VoidSubscription'",
            [(string) $venue->number()],
        ));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Zahlung storniert');
        self::assertCount(0, $crawler->filter('[commandfor="deal-void"]'));
        self::assertCount(1, $crawler->filter('[commandfor="deal-payment"]'), 'the deal can be paid again');
    }

    public function test_a_free_term_says_so_in_the_dialog(): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal, 'venue' => $venue] = $this->won();
        SubscriptionFactory::new()->freeOfCharge()->create(['venue' => $venue]);

        $crawler = $this->client->request('GET', '/admin/crm/deals/'.$deal->id());

        self::assertStringContainsString('Der kostenlose Zeitraum im Tarif Pro', $crawler->filter('#deal-void p')->text());
    }

    public function test_an_ended_term_offers_no_void(): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal, 'venue' => $venue] = $this->won();
        SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('-1 year'), 'endsOn' => new \DateTimeImmutable('-1 day')]);

        $crawler = $this->client->request('GET', '/admin/crm/deals/'.$deal->id());

        self::assertCount(0, $crawler->filter('[commandfor="deal-void"]'));
    }

    public function test_a_term_other_than_the_latest_is_stale_and_stays(): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal, 'venue' => $venue] = $this->paid();
        $first = (string) $this->connection->fetchOne('SELECT id FROM subscriptions WHERE venue_id = ?', [$venue->id()]);
        $end = new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT ends_on FROM subscriptions WHERE id = ?', [$first]));
        SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => $end->modify('+1 day'), 'endsOn' => $end->modify('+1 year')]);

        $this->post($deal, $first);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'nicht mehr stornieren');
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM subscriptions WHERE venue_id = ?', [$venue->id()]));
        self::assertSame(KnownTiers::PRO, $this->tierOf($venue));
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $server
     */
    #[DataProvider('forgedPosts')]
    public function test_a_forged_post_changes_nothing(array $fields, array $server): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal, 'venue' => $venue] = $this->paid();
        $id = (string) $this->connection->fetchOne('SELECT id FROM subscriptions WHERE venue_id = ?', [$venue->id()]);

        $this->client->request('POST', '/admin/crm/deals/'.$deal->id().'/zahlung-stornieren', $fields + ['subscription' => $id], server: $server);

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id(), 303);
        self::assertSame(KnownTiers::PRO, $this->tierOf($venue));
    }

    /**
     * A won Pro deal whose payment was recorded through «Zahlung erfassen».
     *
     * @return array{deal: Deal, venue: Venue}
     */
    private function paid(): array
    {
        $won = $this->won();
        $this->client->request('POST', '/admin/crm/deals/'.$won['deal']->id().'/zahlung', ['_token' => 'csrf-token', 'expected' => ''], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $this->client->followRedirect();

        return $won;
    }

    /**
     * @return array{deal: Deal, venue: Venue}
     */
    private function won(): array
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick', 'tier' => KnownTiers::FREE]);
        $company = CompanyFactory::new()->withVenue($venue)->create();

        return [
            'deal' => DealFactory::new()->inStage('won')->create(['company' => $company, 'venue' => $venue, 'tier' => KnownTiers::PRO]),
            'venue' => $venue,
        ];
    }

    private function post(Deal $deal, string $subscriptionId): void
    {
        $this->client->request('POST', '/admin/crm/deals/'.$deal->id().'/zahlung-stornieren', ['_token' => 'csrf-token', 'subscription' => $subscriptionId], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
    }

    private function tierOf(Venue $venue): string
    {
        return (string) $this->connection->fetchOne('SELECT t.code FROM venues v JOIN tiers t ON t.id = v.tier_id WHERE v.number = ?', [$venue->number()]);
    }

    private function proPaid(Venue $venue): int
    {
        return (int) $this->connection->fetchOne("SELECT count(*) FROM funnel_events WHERE event = 'pro_paid' AND venue_id = ?", [$venue->id()]);
    }
}
