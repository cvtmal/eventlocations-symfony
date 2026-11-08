<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\SubscriptionFactory;
use App\Factory\VenueFactory;
use App\Portal\Security\SubscriptionCancelToken;
use App\Tests\Support\Portal\PortalSession;
use App\Venues\Entity\Subscription;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The reminder mail's «Nicht verlängern» (G9): no login, a GET only asks, the POST cancels the renewal and the page then
 * says so; the tier runs to the end. A bad, unknown or ended link answers 410, a bad token changes nothing.
 */
final class CancelSubscriptionTest extends WebTestCase
{
    use Factories;
    use PortalSession;

    public function test_the_link_asks_without_a_login_and_the_press_cancels_the_renewal(): void
    {
        $term = $this->term();

        $crawler = $this->client->request('GET', $this->url($term));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Nicht verlängern');
        self::assertStringContainsString('Dein Tarif Pro für «Seehaus Meilen» läuft bis am', $crawler->filter('.auth__intro')->first()->text());
        self::assertNull($this->cancelledAt($term), 'a GET changes nothing');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $this->client->submit($crawler->filter('form')->form());

        self::assertResponseRedirects($this->url($term), 303);
        self::assertNotNull($this->cancelledAt($term));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Verlängerung gekündigt');
        self::assertStringContainsString('danach gilt Free', $crawler->filter('.auth__intro')->text());
    }

    public function test_a_term_whose_successor_is_paid_only_says_so(): void
    {
        $term = $this->term();
        SubscriptionFactory::createOne(['venue' => $term->venue(), 'startsOn' => new \DateTimeImmutable('+166 days'), 'endsOn' => new \DateTimeImmutable('+530 days')]);

        $this->client->request('POST', $this->url($term), ['_token' => 'csrf-token'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Schon verlängert');
        self::assertNull($this->cancelledAt($term));
    }

    public function test_a_bad_token_changes_nothing(): void
    {
        $term = $this->term();

        $this->client->request('POST', $this->url($term), ['_token' => 'falsch'], server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->cancelledAt($term));
    }

    public function test_a_forged_unknown_or_ended_link_is_gone(): void
    {
        $ended = SubscriptionFactory::createOne([
            'venue' => VenueFactory::new()->withoutLocation()->create(),
            'startsOn' => new \DateTimeImmutable('-400 days'),
            'endsOn' => new \DateTimeImmutable('-2 days'),
        ]);
        $token = $this->token($this->term());
        // Change one character in the middle (the last one may carry only padding bits, or already be the new one).
        $forged = substr($token, 0, 20).('A' === $token[20] ? 'B' : 'A').substr($token, 21);

        foreach ([$this->url($ended), '/konto/abo-kuendigen/'.$forged, '/konto/abo-kuendigen/'.$this->tokens()->forId('019a0000-0000-7000-8000-000000000000')] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(410, $url);
            self::assertSelectorTextContains('h1', 'Dieser Link gilt nicht mehr');
        }
        self::assertNull($this->cancelledAt($ended));
    }

    private function term(): Subscription
    {
        $venue = VenueFactory::new()->withoutLocation()->create(['name' => 'Seehaus Meilen']);
        $this->connection->executeStatement('DELETE FROM subscriptions WHERE venue_id = ?', [$venue->id()]);

        return SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('-200 days'), 'endsOn' => new \DateTimeImmutable('+165 days')]);
    }

    private function url(Subscription $term): string
    {
        return '/konto/abo-kuendigen/'.$this->token($term);
    }

    private function token(Subscription $term): string
    {
        return $this->tokens()->forId($term->id());
    }

    private function tokens(): SubscriptionCancelToken
    {
        $tokens = self::getContainer()->get(SubscriptionCancelToken::class);
        self::assertInstanceOf(SubscriptionCancelToken::class, $tokens);

        return $tokens;
    }

    private function cancelledAt(Subscription $term): mixed
    {
        return $this->connection->fetchOne('SELECT cancel_requested_at FROM subscriptions WHERE id = ?', [$term->id()]);
    }
}
