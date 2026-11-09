<?php

declare(strict_types=1);

namespace App\Tests\Application\Inquiries;

use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Inquiries\BriefOptOutToken;
use App\Inquiries\Mailer\InquiryMailer;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Inquiries\InquiryFormFields;
use App\Tests\Support\Inquiries\SubmitsInquiries;
use App\Venues\Entity\Venue;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * F13: /sammelanfrage offers the venues that take briefs as named checkboxes, sends one mail per ticked venue and one
 * confirmation to the guest, and refuses the whole brief when a venue stopped taking briefs. The venue's opt-out page
 * and the ways in from the Merkliste and the listing.
 */
final class InquiryBriefPageTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use SubmitsInquiries;

    protected function setUp(): void
    {
        $this->start();
        $this->connection->executeStatement('DELETE FROM funnel_events');
    }

    protected function tearDown(): void
    {
        $this->finish();
        parent::tearDown();
    }

    public function test_offers_accepting_venues_and_names_the_others_only_as_single_inquiries(): void
    {
        $venues = [];
        foreach (range(1, 6) as $i) {
            $venues[] = $this->venue('haus-'.$i, 'Haus '.$i);
        }
        $optedOut = $this->venue('nur-einzeln', 'Nur Einzeln', accepts: false);
        $numbers = [$venues[0]->number(), $optedOut->number(), ...array_map(static fn (Venue $v): int => $v->number(), \array_slice($venues, 1))];

        $crawler = $this->client->request('GET', '/sammelanfrage?nr='.implode(',', $numbers));

        self::assertResponseIsSuccessful();
        $this->assertPrivate();
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
        $boxes = $crawler->filter('input[type="checkbox"][name="inquiry[venues][]"]');
        self::assertSame(
            array_map(static fn (Venue $v): string => (string) $v->number(), $venues),
            $boxes->each(static fn (Crawler $box): string => (string) $box->attr('value')),
        );
        self::assertSame(
            [true, true, true, true, true, false],
            $boxes->each(static fn (Crawler $box): bool => null !== $box->attr('checked')),
            'the first five are ticked',
        );
        self::assertStringStartsWith('Haus 1, ', trim($crawler->filter('label[for="'.$boxes->first()->attr('id').'"]')->text()), 'each box is named');

        $single = $crawler->filter('.brief-page__single');
        self::assertStringContainsString('Nur einzeln anfragbar', $single->text());
        self::assertStringContainsString('Nur Einzeln', $single->text());
        self::assertCount(1, $single->filter('a[href="/eventlokal/nur-einzeln#anfrage"]'));
        self::assertStringNotContainsString('Nur Einzeln', $crawler->filter('form')->text());
        self::assertStringStartsWith('Hallo zusammen', $crawler->filter('textarea[data-inquiry-message]')->text());
        self::assertCount(1, $crawler->filter('script[src*="/js/inquiry"]'), 'inquiry.js moves the caret of the prefilled message (F16 N4)');
    }

    public function test_a_venue_without_an_inquiry_address_is_named_only_as_a_link_to_its_page(): void
    {
        $a = $this->venue('haus-a', 'Haus A');
        $b = $this->venue('haus-b', 'Haus B');
        $noAddress = VenueFactory::createOne(['name' => 'Ohne Adresse']);
        VenueTranslationFactory::createOne(['venue' => $noAddress, 'slug' => 'ohne-adresse', 'summary' => 'Ein Ort für Anlässe.']);

        $crawler = $this->client->request('GET', '/sammelanfrage?nr='.implode(',', [$a->number(), $noAddress->number(), $b->number()]));

        self::assertResponseIsSuccessful();
        self::assertSame(
            [(string) $a->number(), (string) $b->number()],
            $crawler->filter('input[type="checkbox"][name="inquiry[venues][]"]')->each(static fn (Crawler $box): string => (string) $box->attr('value')),
            'never a choice',
        );
        $single = $crawler->filter('.brief-page__single');
        self::assertStringContainsString('Nur einzeln anfragbar', $single->text());
        self::assertStringContainsString('Ohne Adresse', $single->text());
        self::assertCount(1, $single->filter('a[href="/eventlokal/ohne-adresse"]'), 'its page, which has no #anfrage');
    }

    public function test_fewer_than_two_offered_venues_show_no_form(): void
    {
        $open = $this->venue('offen', 'Offen');
        $closed = $this->venue('zu', 'Zu', accepts: false);

        $this->client->request('GET', '/sammelanfrage?nr='.$open->number().','.$closed->number());

        self::assertResponseIsSuccessful();
        $this->assertPrivate();
        self::assertSelectorTextContains('h2', 'Zu wenige Locations');
        self::assertSelectorNotExists('form');
        self::assertSelectorNotExists('script[src*="/js/inquiry"]', 'no message, no caret to move');
    }

    public function test_without_numbers_shows_no_form(): void
    {
        $this->client->request('GET', '/sammelanfrage');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Zu wenige Locations');
        self::assertSelectorNotExists('form');
    }

    public function test_sends_one_mail_per_ticked_venue_and_one_confirmation(): void
    {
        $a = $this->venue('alpha-saal', 'Alpha Saal');
        $b = $this->venue('beta-haus', 'Beta Haus');
        $c = $this->venue('gamma-hof', 'Gamma Hof');
        $received = $this->listen();
        $nr = $a->number().','.$b->number().','.$c->number();

        $this->postBrief($nr, [$a->number(), $c->number()]);

        self::assertResponseStatusCodeSame(303);
        $this->assertPrivate();
        $mails = $this->sentMails();
        self::assertCount(3, $mails);
        [$toA, $toC, $toGuest] = $mails;
        // The test env sends every venue mail to the override and names the venue's own address in a header.
        self::assertSame($a->inquiryEmail(), $this->originalRecipient($toA));
        self::assertSame($c->inquiryEmail(), $this->originalRecipient($toC));
        foreach (['Alpha Saal' => $toA, 'Gamma Hof' => $toC] as $name => $mail) {
            self::assertStringStartsWith('Neue Sammelanfrage für '.$name, (string) $mail->getSubject());
            $content = $this->contentOf($mail);
            self::assertStringContainsString('an 2 Locations', $content);
            self::assertStringContainsString('/sammelanfrage/abbestellen/', $content);
            self::assertMatchesRegularExpression('#^<https?://[^/>]+/sammelanfrage/abbestellen/[^>]+>$#', (string) $mail->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());
            self::assertSame('List-Unsubscribe=One-Click', $mail->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());
            self::assertStringNotContainsString('Beta Haus', $content);
        }
        self::assertStringNotContainsString('Gamma Hof', $this->contentOf($toA));
        self::assertStringNotContainsString('Alpha Saal', $this->contentOf($toC));
        self::assertSame(['anna@example.ch'], $this->recipients($toGuest));
        $guestContent = $this->contentOf($toGuest);
        self::assertStringContainsString('Alpha Saal', $guestContent);
        self::assertStringContainsString('Gamma Hof', $guestContent);
        self::assertStringNotContainsString('Beta Haus', $guestContent);

        $children = $this->rows();
        self::assertCount(2, $children);
        self::assertSame([$a->id(), $c->id()], array_column($children, 'venue_id'));
        self::assertSame(['sent', 'sent'], array_column($children, 'venue_mail_status'));
        self::assertSame(['skipped', 'skipped'], array_column($children, 'guest_mail_status'));
        $brief = $this->connection->fetchAllAssociative('SELECT id, venue_count, guest_mail_status FROM inquiry_briefs');
        self::assertCount(1, $brief);
        self::assertSame(2, $brief[0]['venue_count']);
        self::assertSame('sent', $brief[0]['guest_mail_status']);
        self::assertSame([$brief[0]['id'], $brief[0]['id']], array_column($children, 'brief_id'));
        self::assertEqualsCanonicalizing(array_column($children, 'id'), $received->ids);
        self::assertCount(2, $received->ids);
        self::assertSame(
            ['brief_sent' => 1, 'inquiry_delivered' => 2],
            array_map('intval', $this->connection->fetchAllKeyValue('SELECT event, count(*) FROM funnel_events GROUP BY event ORDER BY event')),
        );

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/sammelanfrage/gesendet?', $location);
        self::assertStringContainsString('_hash=', $location);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $this->assertPrivate();
        self::assertSelectorTextContains('.brief-page__reached', 'Alpha Saal');
        self::assertSelectorTextContains('.brief-page__reached', 'Gamma Hof');
        self::assertSelectorTextNotContains('.brief-page__reached', 'Beta Haus');

        $this->client->request('GET', '/sammelanfrage/gesendet?brief='.$brief[0]['id']);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_same_brief_twice_sends_nothing_new(): void
    {
        $a = $this->venue('alpha-saal', 'Alpha Saal');
        $b = $this->venue('beta-haus', 'Beta Haus');
        $nr = $a->number().','.$b->number();
        $this->postBrief($nr, [$a->number(), $b->number()]);
        self::assertEmailCount(3);
        $received = $this->listen();

        $this->postBrief($nr, [$a->number(), $b->number()]);

        self::assertResponseStatusCodeSame(303);
        self::assertEmailCount(0);
        self::assertCount(2, $this->rows());
        self::assertSame([], $received->ids);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM funnel_events WHERE event = 'brief_sent'"));
    }

    public function test_a_venue_opting_out_after_the_page_refuses_the_whole_brief(): void
    {
        $a = $this->venue('alpha-saal', 'Alpha Saal');
        $b = $this->venue('beta-haus', 'Beta Haus');
        $c = $this->venue('gamma-hof', 'Gamma Hof');
        $nr = $a->number().','.$b->number().','.$c->number();
        $this->client->request('GET', '/sammelanfrage?nr='.$nr);
        self::assertResponseIsSuccessful();
        $this->connection->executeStatement('UPDATE venues SET accepts_multi_inquiries = false WHERE id = ?', [$b->id()]);

        $crawler = $this->postBrief($nr, [$a->number(), $b->number()]);

        self::assertResponseStatusCodeSame(422);
        $this->assertPrivate();
        self::assertStringContainsString('Eine der Locations nimmt gerade keine Sammelanfragen mehr an', $crawler->filter('main')->text());
        self::assertSame(
            [(string) $a->number(), (string) $c->number()],
            $crawler->filter('input[name="inquiry[venues][]"]')->each(static fn (Crawler $box): string => (string) $box->attr('value')),
        );
        self::assertStringContainsString('Beta Haus', $crawler->filter('.brief-page__single')->text());
        $this->assertNothingStored();
    }

    public function test_one_ticked_venue_is_refused(): void
    {
        $a = $this->venue('alpha-saal', 'Alpha Saal');
        $b = $this->venue('beta-haus', 'Beta Haus');

        $crawler = $this->postBrief($a->number().','.$b->number(), [$a->number()]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Bitte hake zwei bis fünf Locations an.', $crawler->filter('.brief-page__venues')->text());
        self::assertSame('-1', $crawler->filter('.inquiry-form__summary[role="alert"][data-inquiry-focus]')->attr('tabindex'), 'inquiry.js focuses the summary, so it is read');
        $this->assertNothingStored();
    }

    public function test_the_summary_links_each_invalid_field_in_form_order(): void
    {
        $a = $this->venue('alpha-saal', 'Alpha Saal');
        $b = $this->venue('beta-haus', 'Beta Haus');

        $crawler = $this->client->request(
            'POST',
            '/sammelanfrage?nr='.$a->number().','.$b->number(),
            InquiryFormFields::valid(['venues' => [(string) $a->number()], 'lastName' => '', 'eventType' => '']),
            [],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );

        self::assertResponseStatusCodeSame(422);
        $links = $crawler->filter('.inquiry-form__summary li a');
        $first = (string) $crawler->filter('input[name="inquiry[venues][]"]')->first()->attr('id');
        self::assertSame(
            ['#'.$first, '#inquiry_eventType', '#inquiry_lastName'],
            $links->each(static fn (Crawler $link): string => (string) $link->attr('href')),
            'the venue list first, as the page shows it',
        );
        self::assertSame(['An diese Locations', 'Anlass', 'Name'], $links->each(static fn (Crawler $link): string => $link->text()));
        $this->assertNothingStored();
    }

    public function test_the_opt_out_page_asks_and_switches_briefs_off(): void
    {
        $venue = $this->venue('alpha-saal', 'Alpha Saal');
        $url = '/sammelanfrage/abbestellen/'.$this->optOutToken($venue);

        $crawler = $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Alpha Saal', $crawler->filter('h1')->text());
        self::assertCount(1, $crawler->filter('form[method="post"][action="'.$url.'"] button[type="submit"]'));
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));
        $this->assertPrivate();
        self::assertTrue($this->acceptsBriefs($venue));

        $this->client->request('POST', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Abbestellt');
        $this->assertPrivate();
        self::assertFalse($this->acceptsBriefs($venue));

        $this->client->request('POST', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Abbestellt');
    }

    public function test_a_wrong_opt_out_token_is_not_found(): void
    {
        $venue = $this->venue('alpha-saal', 'Alpha Saal');
        $other = $this->venue('beta-haus', 'Beta Haus');
        $mac = substr($this->optOutToken($other), \strlen((string) $other->number()));

        $this->client->request('GET', '/sammelanfrage/abbestellen/'.$venue->number().$mac);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/sammelanfrage/abbestellen/'.$venue->number().$mac);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/sammelanfrage/abbestellen/kaputt');
        self::assertResponseStatusCodeSame(404);

        self::assertTrue($this->acceptsBriefs($venue));
    }

    public function test_the_opt_out_page_refuses_other_methods(): void
    {
        $venue = $this->venue('alpha-saal', 'Alpha Saal');

        $this->client->request('PUT', '/sammelanfrage/abbestellen/'.$this->optOutToken($venue));

        self::assertResponseStatusCodeSame(405);
        self::assertTrue($this->acceptsBriefs($venue));
    }

    public function test_the_merkliste_links_to_a_brief_of_its_venues(): void
    {
        $a = $this->venue('alpha-saal', 'Alpha Saal');
        $b = $this->venue('beta-haus', 'Beta Haus');
        $this->client->getCookieJar()->set(new Cookie('merkliste', $a->number().','.$b->number()));

        $crawler = $this->client->request('GET', '/merkliste');

        self::assertResponseIsSuccessful();
        $links = $crawler->filter('a[href^="/sammelanfrage?nr="]');
        self::assertCount(1, $links);
        self::assertEqualsCanonicalizing(
            [$a->number(), $b->number()],
            array_map('intval', explode(',', urldecode(substr((string) $links->attr('href'), \strlen('/sammelanfrage?nr='))))),
        );
    }

    public function test_the_listing_links_to_a_brief_of_its_venues(): void
    {
        $this->venue('alpha-saal', 'Alpha Saal');
        $this->venue('beta-haus', 'Beta Haus');

        $crawler = $this->client->request('GET', '/eventlokale');

        self::assertResponseIsSuccessful();
        $links = $crawler->filter('a[href^="/sammelanfrage?nr="]');
        self::assertCount(1, $links);
        self::assertStringContainsString('quelle=liste', (string) $links->attr('href'));
    }

    public function test_a_brief_from_a_result_list_ticks_nothing_and_refuses_a_submit_without_a_pick(): void
    {
        $nr = implode(',', [$this->venue('erst-saal', 'Erst Saal')->number(), $this->venue('zweit-haus', 'Zweit Haus')->number(), $this->venue('dritt-hof', 'Dritt Hof')->number()]);

        $crawler = $this->client->request('GET', '/sammelanfrage?nr='.$nr.'&quelle=liste');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('input[name="inquiry[venues][]"]'));
        self::assertCount(0, $crawler->filter('input[name="inquiry[venues][]"][checked]'), 'D59: from a result list the organizer picks');
        self::assertStringContainsString('quelle=liste', (string) $crawler->filter('form.inquiry-form__form')->attr('action'));

        $this->client->request('POST', '/sammelanfrage?nr='.$nr.'&quelle=liste', InquiryFormFields::valid(), [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Bitte hake zwei bis fünf Locations an.');
        $this->assertNothingStored();
    }

    /**
     * PO: the pages depend on the guest's selection or a mailed link, so search engines get `noindex` (meta and
     * header) on every state (the 429 and 503 re-renders go through the same `page()` and template). robots.txt
     * stays open on purpose: a disallowed page is never fetched, so its `noindex` would go unseen and the bare URL
     * could still be indexed from a link. The sitemap and robots checks guard against a later change.
     */
    public function test_every_brief_page_is_noindex_and_left_out_of_the_sitemap(): void
    {
        $a = $this->venue('alpha-saal', 'Alpha Saal');
        $b = $this->venue('beta-haus', 'Beta Haus');
        $optOut = '/sammelanfrage/abbestellen/'.$this->optOutToken($a);

        $both = $a->number().','.$b->number();
        foreach (['/sammelanfrage' => 200, '/sammelanfrage?nr='.$a->number() => 200, '/sammelanfrage?nr='.$both => 200, $optOut => 200, '/sammelanfrage/abbestellen/kaputt' => 404] as $url => $status) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame($status, $url);
            $this->assertNoindex($url);
        }
        $this->client->request('GET', '/sammelanfrage?nr='.$both);
        self::assertSelectorExists('input[name="inquiry[venues][]"]');
        self::assertSelectorExists('meta[name="description"]', 'Lighthouse SEO asks for one even on a noindex page (F16)');
        $this->postBrief($both, [$a->number()]);
        self::assertResponseStatusCodeSame(422);
        $this->assertNoindex('422 re-render');
        $this->postBrief($both, [$a->number(), $b->number()]);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $this->assertNoindex('/sammelanfrage/gesendet');
        $this->client->request('POST', $optOut);
        $this->assertNoindex('opt-out done');

        foreach (['/sitemap.xml', '/sitemap-pages.xml', '/sitemap-venues-1.xml', '/robots.txt'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('sammelanfrage', (string) $this->client->getResponse()->getContent(), $path);
        }
    }

    private function assertNoindex(string $page): void
    {
        self::assertSame('noindex, nofollow', $this->client->getResponse()->headers->get('X-Robots-Tag'), $page);
        self::assertCount(1, $this->client->getCrawler()->filter('meta[name="robots"][content="noindex, nofollow"]'), $page);
        self::assertCount(0, $this->client->getCrawler()->filter('link[rel="canonical"]'), $page);
    }

    /**
     * @param list<int> $ticked
     */
    private function postBrief(string $nr, array $ticked): Crawler
    {
        return $this->client->request(
            'POST',
            '/sammelanfrage?nr='.$nr,
            InquiryFormFields::valid(['venues' => array_map('strval', $ticked)]),
            [],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
    }

    private function assertNothingStored(): void
    {
        self::assertSame([], $this->rows());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM inquiry_briefs'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM funnel_events'));
        self::assertEmailCount(0);
    }

    private function venue(string $slug, string $name, bool $accepts = true): Venue
    {
        $venue = VenueFactory::new()->withContact()->create(['name' => $name]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => $slug, 'summary' => 'Ein Ort für Anlässe.']);
        if (!$accepts) {
            $venue->changeMultiInquiryConsent(false);
            $this->em->flush();
        }

        return $venue;
    }

    private function optOutToken(Venue $venue): string
    {
        $tokens = self::getContainer()->get(BriefOptOutToken::class);
        self::assertInstanceOf(BriefOptOutToken::class, $tokens);

        return $tokens->forVenue($venue->number());
    }

    private function acceptsBriefs(Venue $venue): bool
    {
        return (bool) $this->connection->fetchOne('SELECT accepts_multi_inquiries FROM venues WHERE id = ?', [$venue->id()]);
    }

    private function originalRecipient(Email $mail): ?string
    {
        return $mail->getHeaders()->get(InquiryMailer::ORIGINAL_RECIPIENT_HEADER)?->getBodyAsString();
    }

    /**
     * @return list<string>
     */
    private function recipients(Email $mail): array
    {
        return array_values(array_map(static fn ($address): string => $address->getAddress(), $mail->getTo()));
    }

    /**
     * @return list<Email> in the order they were sent
     */
    private function sentMails(): array
    {
        $mails = [];
        foreach (array_filter(self::getMailerEvents(), static fn (MessageEvent $event): bool => !$event->isQueued()) as $event) {
            $message = $event->getMessage();
            self::assertInstanceOf(Email::class, $message);
            $mails[] = $message;
        }

        return $mails;
    }

    private function contentOf(Email $mail): string
    {
        return implode("\n", [$mail->getHeaders()->toString(), (string) $mail->getHtmlBody(), (string) $mail->getTextBody()]);
    }
}
