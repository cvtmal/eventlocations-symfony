<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\VenueFactory;
use App\Portal\Security\ReportOptOutToken;
use App\Tests\Support\Portal\PortalSession;
use App\Venues\Entity\Venue;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The monthly report's opt-out link (G10, RFC 8058): no login, a GET only shows the state, any POST (the mail client's
 * one-click POST without cookie or form token included) turns the report off with 200 in place and no session;
 * «wieder abonnieren» turns it on again. A forged link or a gone venue answers 410, a malformed token 404.
 */
final class ReportOptOutTest extends WebTestCase
{
    use Factories;
    use PortalSession;

    public function test_a_get_only_asks(): void
    {
        $venue = $this->venue();

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Monatsbericht abbestellen');
        self::assertStringContainsString('Möchtest du den Monatsbericht für «Seehaus Meilen» nicht mehr erhalten?', $crawler->filter('.auth__intro')->text());
        self::assertSame(0, $this->optOuts($venue), 'a GET changes nothing');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'), 'no session');
    }

    public function test_the_one_click_post_opts_out_in_place_without_a_session(): void
    {
        $venue = $this->venue();

        foreach ([1, 2] as $click) {
            $this->client->getCookieJar()->clear();
            $this->client->request('POST', $this->url($venue), ['List-Unsubscribe' => 'One-Click']);

            self::assertResponseStatusCodeSame(200, 'click '.$click);
            self::assertFalse($this->client->getResponse()->headers->has('Location'), 'no redirect');
            self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'), 'no session');
            self::assertSame(1, $this->optOuts($venue), 'idempotent');
            self::assertSelectorTextContains('.auth__intro', 'Du erhältst den Monatsbericht für «Seehaus Meilen» nicht mehr.');
        }

        $crawler = $this->client->request('GET', $this->url($venue));
        self::assertSelectorTextContains('.auth__intro', 'nicht mehr');
        self::assertSelectorTextContains('button', 'wieder abonnieren');

        $this->client->submit($crawler->filter('form')->form());
        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->optOuts($venue));
        self::assertSelectorTextContains('.auth__intro', 'Du erhältst den Monatsbericht für «Seehaus Meilen» wieder');
    }

    public function test_a_post_without_a_body_opts_out_too(): void
    {
        $venue = $this->venue();

        $this->client->request('POST', $this->url($venue));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->optOuts($venue));
    }

    public function test_a_forged_or_gone_link_is_gone_and_a_malformed_one_is_not_found(): void
    {
        $token = $this->tokens()->forVenueId($this->venue()->id());
        $forged = substr($token, 0, 20).('A' === $token[20] ? 'B' : 'A').substr($token, 21);

        foreach (['/konto/bericht-abbestellen/'.$forged, '/konto/bericht-abbestellen/'.$this->tokens()->forVenueId('019a0000-0000-7000-8000-000000000000')] as $url) {
            $this->client->request('POST', $url, ['List-Unsubscribe' => 'One-Click']);
            self::assertResponseStatusCodeSame(410, $url);
            self::assertSelectorTextContains('h1', 'Dieser Link gilt nicht mehr');
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM portal_report_opt_outs'));

        $this->client->request('GET', '/konto/bericht-abbestellen/kurz');
        self::assertResponseStatusCodeSame(404);
    }

    private function venue(): Venue
    {
        return VenueFactory::new()->withoutLocation()->create(['name' => 'Seehaus Meilen']);
    }

    private function url(Venue $venue): string
    {
        return '/konto/bericht-abbestellen/'.$this->tokens()->forVenueId($venue->id());
    }

    private function tokens(): ReportOptOutToken
    {
        $tokens = self::getContainer()->get(ReportOptOutToken::class);
        self::assertInstanceOf(ReportOptOutToken::class, $tokens);

        return $tokens;
    }

    private function optOuts(Venue $venue): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM portal_report_opt_outs WHERE venue_id = ?', [$venue->id()]);
    }
}
