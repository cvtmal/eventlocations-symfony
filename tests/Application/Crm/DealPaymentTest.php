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
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * «Zahlung erfassen» through HTTP (G9, D20): the record's action and form with the invoice number, the form the board's
 * prompt posts, its tokens, and what a stale, spent or overlapping offer says.
 */
final class DealPaymentTest extends WebTestCase
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

    public function test_the_record_offers_the_payment_and_recording_it_ends_the_offer(): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal, 'venue' => $venue] = $this->won();

        $crawler = $this->client->request('GET', '/admin/crm/deals/'.$deal->id());

        self::assertResponseIsSuccessful();
        self::assertSame('Zahlung erfassen', $crawler->filter('[commandfor="deal-payment"]')->text());
        self::assertStringContainsString('«Schloss Seeblick» erhält den Tarif «Pro» vom', $crawler->filter('#deal-payment p')->first()->text());
        self::assertSame('', $crawler->filter('#deal-payment-form input[name="expected"]')->attr('value'));
        self::assertStringContainsString('Gratis', $crawler->filter('#location .crm-side__detail')->text());

        $this->client->submit($crawler->filter('#deal-payment-form')->form(), ['invoice_ref' => ' RE-2026-17 '], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id(), 303);
        self::assertSame(KnownTiers::PRO, $this->tierOf($venue));
        self::assertSame('RE-2026-17', $this->connection->fetchOne('SELECT invoice_ref FROM subscriptions WHERE venue_id = ?', [$venue->id()]));
        self::assertSame(2, $this->auditRows('venue', (string) $venue->number()));
        self::assertSame(1, $this->auditRows('deal', $deal->id()));
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(1, $this->purger()->listings);

        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('.toast', 'Zahlung erfasst.');
        self::assertCount(0, $crawler->filter('[commandfor="deal-payment"]'));
        self::assertCount(0, $crawler->filter('#deal-payment'));
        self::assertStringContainsString('Bezahlt bis', $crawler->filter('.crm-record')->text());
        // The Eintrag's card reads the tier the venue has now, by its name.
        self::assertStringContainsString('Pro', $crawler->filter('#location .crm-side__detail')->text());
    }

    public function test_a_deal_that_is_not_won_has_no_action(): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal] = $this->won('offer');

        $crawler = $this->client->request('GET', '/admin/crm/deals/'.$deal->id());

        self::assertCount(0, $crawler->filter('[commandfor="deal-payment"]'));
    }

    public function test_the_board_prompt_comes_back_to_the_board(): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal, 'venue' => $venue] = $this->won();

        $this->post($deal, ['return_to' => '/admin/crm/deals?owner=keller']);

        self::assertResponseRedirects('/admin/crm/deals?owner=keller', 303);
        self::assertSame(KnownTiers::PRO, $this->tierOf($venue));
    }

    public function test_a_foreign_return_address_goes_to_the_record(): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal] = $this->won();

        $this->post($deal, ['return_to' => 'https://example.invalid/admin/']);

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id(), 303);
    }

    public function test_a_second_click_is_stale_and_records_nothing_twice(): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal, 'venue' => $venue] = $this->won();

        $this->post($deal);
        $this->client->followRedirect();
        $this->post($deal);

        self::assertSame(1, $this->auditRows('deal', $deal->id()));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM subscriptions WHERE venue_id = ?', [$venue->id()]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'inzwischen eine Zahlung erfasst');
    }

    public function test_an_overlapping_term_says_so(): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal, 'venue' => $venue] = $this->won(start: new \DateTimeImmutable('today'), end: new \DateTimeImmutable('+1 year'));
        SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('-1 month'), 'endsOn' => new \DateTimeImmutable('+1 month')]);
        $paidUntil = (new \DateTimeImmutable('+1 month'))->format('Y-m-d');

        $this->post($deal, ['expected' => $paidUntil]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'überschneidet sich');
        self::assertSame(0, $this->auditRows('deal', $deal->id()));
    }

    public function test_the_dialog_prefills_the_price_and_a_free_term_with_a_note_records_no_payment(): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal, 'venue' => $venue] = $this->won();

        $crawler = $this->client->request('GET', '/admin/crm/deals/'.$deal->id());
        self::assertSame('199', $crawler->filter('#deal-payment-amount')->attr('value'));
        $this->client->submit($crawler->filter('#deal-payment-form')->form(), ['amount' => '0', 'note' => ' Pilot '], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertSame(KnownTiers::PRO, $this->tierOf($venue));
        self::assertSame([0, 'Pilot'], array_values((array) $this->connection->fetchAssociative('SELECT amount_rappen, note FROM subscriptions WHERE venue_id = ?', [$venue->id()])));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM funnel_events WHERE event = 'pro_paid' AND venue_id = ?", [$venue->id()]), 'D18: a free term is no payment');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Kostenlos bis', $crawler->filter('.crm-record')->text());
    }

    public function test_a_paid_amount_in_francs_is_kept_in_rappen(): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal, 'venue' => $venue] = $this->won();

        $this->post($deal, ['amount' => '149,50']);

        self::assertSame(14950, (int) $this->connection->fetchOne('SELECT amount_rappen FROM subscriptions WHERE venue_id = ?', [$venue->id()]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM funnel_events WHERE event = 'pro_paid' AND venue_id = ?", [$venue->id()]));
    }

    public function test_a_free_term_without_a_note_says_so_and_records_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal, 'venue' => $venue] = $this->won();

        $this->post($deal, ['amount' => '0', 'note' => '  ']);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'braucht eine Notiz');
        self::assertSame(KnownTiers::FREE, $this->tierOf($venue));
        self::assertSame(0, $this->auditRows('deal', $deal->id()));
    }

    public function test_an_unknown_deal_is_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('POST', '/admin/crm/deals/019a0000-0000-7000-8000-000000000000/zahlung', ['_token' => 'csrf-token', 'expected' => ''], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function malformedFields(): iterable
    {
        yield 'a day of another shape' => [['expected' => '8.11.2026']];
        yield 'a word' => [['expected' => 'free']];
        yield 'an invoice number over 60 characters' => [['invoice_ref' => str_repeat('R', 61)]];
        yield 'an invoice number with a NUL' => [['invoice_ref' => "RE\0-17"]];
        yield 'a negative amount' => [['amount' => '-1']];
        yield 'an amount with three decimals' => [['amount' => '199.005']];
        yield 'an amount over CHF 99999.99' => [['amount' => '100000']];
        yield 'an amount in words' => [['amount' => 'gratis']];
        yield 'a note over 200 characters' => [['amount' => '0', 'note' => str_repeat('P', 201)]];
        yield 'a note with a NUL' => [['amount' => '0', 'note' => "Pi\0lot"]];
    }

    /**
     * @param array<string, string> $fields
     */
    #[DataProvider('malformedFields')]
    public function test_a_malformed_field_is_refused_before_the_command(array $fields): void
    {
        $this->logInAs(AdminRole::Editor);
        ['deal' => $deal, 'venue' => $venue] = $this->won();

        $this->post($deal, $fields);

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id(), 303);
        self::assertSame(KnownTiers::FREE, $this->tierOf($venue));
        self::assertSame(0, $this->auditRows('deal', $deal->id()));
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

        $this->client->request('POST', '/admin/crm/deals/'.$deal->id().'/zahlung', $fields + ['expected' => ''], server: $server);

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id(), 303);
        self::assertSame(KnownTiers::FREE, $this->tierOf($venue));
    }

    /**
     * @return array{deal: Deal, venue: Venue}
     */
    private function won(string $stage = 'won', ?\DateTimeImmutable $start = null, ?\DateTimeImmutable $end = null): array
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick', 'tier' => KnownTiers::FREE]);
        $company = CompanyFactory::new()->withVenue($venue)->create();

        return [
            'deal' => DealFactory::new()->inStage($stage)->create(['company' => $company, 'venue' => $venue, 'tier' => KnownTiers::PRO, 'contractStartOn' => $start, 'contractEndOn' => $end]),
            'venue' => $venue,
        ];
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(Deal $deal, array $fields = []): void
    {
        $this->client->request('POST', '/admin/crm/deals/'.$deal->id().'/zahlung', $fields + ['_token' => 'csrf-token', 'expected' => ''], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
    }

    private function tierOf(Venue $venue): string
    {
        return (string) $this->connection->fetchOne('SELECT t.code FROM venues v JOIN tiers t ON t.id = v.tier_id WHERE v.number = ?', [$venue->number()]);
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
