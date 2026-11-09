<?php

declare(strict_types=1);

namespace App\Tests\Application\Inquiries;

use App\Factory\InquiryFactory;
use App\Factory\InquiryFeedbackFactory;
use App\Inquiries\Entity\Inquiry;
use App\Inquiries\FeedbackToken;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The links of the feedback mail (G16): the two questions, the answer stored and changeable, the opt-out (GET asks,
 * POST writes, also RFC 8058's one-click). Every answer is private: no cache, no index, no session, no beacon.
 */
final class InquiryFeedbackPageTest extends WebTestCase
{
    use Factories;

    private const array SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection()->isTransactionActive()) {
            $this->connection()->rollBack();
        }
        parent::tearDown();
    }

    public function test_a_get_asks_both_questions_and_writes_nothing(): void
    {
        $inquiry = $this->mailed();

        $crawler = $this->client->request('GET', $this->url($inquiry));

        self::assertResponseIsSuccessful();
        self::assertSame(\sprintf('Wie lief es mit %s?', $inquiry->venueName()), trim($crawler->filter('h1')->text()));
        self::assertSame(
            [\sprintf('Hat sich %s bei dir gemeldet?', $inquiry->venueName()), 'Hat es geklappt?'],
            $crawler->filter('legend')->each(static fn ($legend): string => $legend->text()),
        );
        self::assertCount(5, $crawler->filter('input[type="radio"]'));
        self::assertCount(0, $crawler->filter('input[type="radio"][checked]'));
        self::assertCount(1, $crawler->filter('a[href="'.$this->url($inquiry).'/abmelden"]'));
        self::assertNull($this->answer($inquiry));
        $this->assertPrivate('same-origin');
    }

    public function test_an_answer_is_stored_and_can_be_changed_without_moving_its_month(): void
    {
        $inquiry = $this->mailed();

        $crawler = $this->client->request('GET', $this->url($inquiry));
        $this->client->submit($crawler->selectButton('Rückmeldung senden')->form(['feedback[venueReplied]' => '1', 'feedback[outcome]' => 'yes']), [], self::SAME_ORIGIN);

        self::assertResponseIsSuccessful();
        self::assertSame('Danke für deine Rückmeldung!', trim($this->client->getCrawler()->filter('h1')->text()));
        $this->assertPrivate('same-origin');
        $first = $this->answer($inquiry);
        self::assertNotNull($first);
        self::assertSame([true, 'yes'], [$first['venue_replied'], $first['outcome']]);

        $crawler = $this->client->request('GET', $this->url($inquiry));
        self::assertCount(2, $crawler->filter('input[type="radio"][checked]'), 'the stored answer is filled in');
        $this->client->submit($crawler->selectButton('Rückmeldung senden')->form(['feedback[venueReplied]' => '0', 'feedback[outcome]' => 'open']), [], self::SAME_ORIGIN);

        self::assertSame([false, 'open', $first['answered_at']], array_values($this->answer($inquiry) ?? []));
    }

    public function test_a_missing_answer_is_a_422_with_both_errors_and_writes_nothing(): void
    {
        $inquiry = $this->mailed();

        $crawler = $this->client->request('GET', $this->url($inquiry));
        $this->client->submit($crawler->selectButton('Rückmeldung senden')->form(), [], self::SAME_ORIGIN);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Bitte wähle Ja oder Nein.', 'Bitte wähle eine Antwort.'], $this->client->getCrawler()->filter('.feedback-form__error')->each(static fn ($error): string => trim($error->text())));
        self::assertNull($this->answer($inquiry));
    }

    /**
     * A browser without Sec-Fetch-Site (older Safari and Firefox) proves the origin with the Origin header, which the
     * page's `same-origin` referrer policy keeps; another site's post is refused.
     */
    public function test_an_answer_with_only_the_origin_header_counts_and_a_cross_site_post_does_not(): void
    {
        $inquiry = $this->mailed();
        $fields = ['feedback' => ['venueReplied' => '1', 'outcome' => 'no', '_token' => 'csrf-token']];

        $this->client->request('POST', $this->url($inquiry), $fields, [], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->answer($inquiry));

        $this->client->request('POST', $this->url($inquiry), $fields, [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseIsSuccessful();
        self::assertSame([true, 'no'], \array_slice(array_values($this->answer($inquiry) ?? []), 0, 2));
    }

    public function test_the_opt_out_asks_then_stops_the_mails_once(): void
    {
        $inquiry = $this->mailed();
        $url = $this->url($inquiry).'/abmelden';

        $crawler = $this->client->request('GET', $url);
        self::assertSame('Keine Fragen mehr nach einer Anfrage?', trim($crawler->filter('h1')->text()));
        self::assertCount(1, $crawler->filter('form[method="post"][action="'.$url.'"] button[type="submit"]'));
        self::assertFalse($this->optedOut($inquiry));
        $this->assertPrivate();

        // RFC 8058's one-click POST comes from the mail client: no Sec-Fetch-Site, no CSRF token.
        $crawler = $this->client->request('POST', $url, ['List-Unsubscribe' => 'One-Click']);
        self::assertResponseIsSuccessful();
        self::assertSame('Du bist abgemeldet', trim($crawler->filter('h1')->text()));
        $this->assertPrivate();
        self::assertTrue($this->optedOut($inquiry));
        $at = $this->connection()->fetchOne('SELECT opted_out_at FROM inquiry_feedback');

        $this->client->request('POST', $url);
        $crawler = $this->client->request('GET', $url);
        self::assertSame('Du bist abgemeldet', trim($crawler->filter('h1')->text()));
        self::assertSame($at, $this->connection()->fetchOne('SELECT opted_out_at FROM inquiry_feedback'));
    }

    public function test_a_wrong_token_an_inquiry_without_mail_or_a_wrong_method_is_private_and_writes_nothing(): void
    {
        $skipped = InquiryFeedbackFactory::new()->optedOut(skipped: true)->create()->inquiry();
        $none = InquiryFactory::createOne();

        foreach (['/rueckmeldung/'.str_repeat('A', 44), '/rueckmeldung/kurz', $this->url($skipped), $this->url($none), $this->url($none).'/abmelden'] as $url) {
            $this->client->request('POST', $url, ['feedback' => ['venueReplied' => '1', 'outcome' => 'yes']], [], self::SAME_ORIGIN);
            self::assertResponseStatusCodeSame(404, $url);
            $this->assertPrivate();
        }
        $this->client->request('PUT', $this->url($this->mailed()));
        self::assertResponseStatusCodeSame(405);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM inquiry_feedback WHERE answered_at IS NOT NULL'));
    }

    private function mailed(): Inquiry
    {
        return InquiryFeedbackFactory::createOne()->inquiry();
    }

    private function url(Inquiry $inquiry): string
    {
        $tokens = self::getContainer()->get(FeedbackToken::class);
        self::assertInstanceOf(FeedbackToken::class, $tokens);

        return '/rueckmeldung/'.$tokens->forInquiry($inquiry->id());
    }

    /**
     * @return array{venue_replied: bool, outcome: string, answered_at: string}|null
     */
    private function answer(Inquiry $inquiry): ?array
    {
        /** @var array{venue_replied: bool, outcome: string, answered_at: string}|false $row */
        $row = $this->connection()->fetchAssociative(
            'SELECT venue_replied, outcome, answered_at FROM inquiry_feedback WHERE inquiry_id = ? AND answered_at IS NOT NULL',
            [$inquiry->id()],
        );

        return false === $row ? null : $row;
    }

    private function optedOut(Inquiry $inquiry): bool
    {
        return (bool) $this->connection()->fetchOne('SELECT opted_out_at IS NOT NULL FROM inquiry_feedback WHERE inquiry_id = ?', [$inquiry->id()]);
    }

    private function assertPrivate(string $referrer = 'no-referrer'): void
    {
        $response = $this->client->getResponse();
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame($referrer, $response->headers->get('Referrer-Policy'));
        self::assertSame([], $response->headers->getCookies());
        self::assertStringNotContainsString('data-stats-url', (string) $response->getContent());
        self::assertStringNotContainsString('rel="canonical"', (string) $response->getContent());
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
