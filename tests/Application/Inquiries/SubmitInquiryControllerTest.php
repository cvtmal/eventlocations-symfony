<?php

declare(strict_types=1);

namespace App\Tests\Application\Inquiries;

use App\Factory\CompanyFactory;
use App\Factory\VenueSlugHistoryFactory;
use App\Inquiries\Event\InquirySubmitted;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Inquiries\InquiryFormFields;
use App\Tests\Support\Inquiries\SubmitsInquiries;
use App\Tests\Support\Venues\DetailVenues;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Log\Logger;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /hochzeitslocation/{slug}/anfrage. A dead SMTP server has its own class,
 * `SubmitInquiryDeliveryFailureTest`: it needs another DSN before the kernel boots. Mail errors
 * other than a transport error are in `SubmitInquiryMailErrorTest`.
 */
final class SubmitInquiryControllerTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use SubmitsInquiries;

    private const SLUG = DetailVenues::FULL_SLUG;
    private const OVERRIDE = 'override@hochzeitslocation.test';

    protected function setUp(): void
    {
        $this->start();
    }

    protected function tearDown(): void
    {
        $this->finish();
        parent::tearDown();
    }

    public function test_success_stores_mails_publishes_and_redirects_to_the_confirmation(): void
    {
        DetailVenues::full($this->em);
        $received = $this->listen();

        $this->post(self::SLUG, InquiryFormFields::valid(['eventDate' => '2030-06-15']));

        self::assertResponseStatusCodeSame(303);
        $this->assertPrivate();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/hochzeitslocation/'.self::SLUG.'?', $location);
        self::assertStringContainsString('anfrage=gesendet', $location);
        self::assertStringContainsString('_hash=', $location);
        self::assertStringEndsWith('#anfrage', $location);

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('Schloss Seeblick', $rows[0]['venue_name']);
        self::assertSame(self::SLUG, $rows[0]['venue_slug']);
        self::assertSame('info@seeblick.example', $rows[0]['recipient_email']);
        self::assertSame(self::OVERRIDE, $rows[0]['delivered_to']);
        self::assertSame('wedding', $rows[0]['occasion']);
        self::assertSame(120, $rows[0]['guest_count']);
        self::assertSame('2030-06-15', $rows[0]['event_date']);
        self::assertSame('Anna', $rows[0]['first_name']);
        self::assertSame('Muster', $rows[0]['last_name']);
        self::assertSame('anna@example.ch', $rows[0]['email']);
        self::assertSame('044 123 45 67', $rows[0]['phone']);
        self::assertSame('de', $rows[0]['locale']);
        self::assertSame('sent', $rows[0]['venue_mail_status']);
        self::assertSame('sent', $rows[0]['guest_mail_status']);
        self::assertNotNull($rows[0]['venue_mail_sent_at']);

        self::assertEmailCount(2);
        self::assertSame([$rows[0]['id']], $received->ids);

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#anfrage form');
        self::assertSelectorTextContains('#anfrage h2', 'Danke');
    }

    public function test_the_same_inquiry_twice_is_one_row_and_one_pair_of_mails(): void
    {
        DetailVenues::full($this->em);
        $received = $this->listen();

        $this->post(self::SLUG, InquiryFormFields::valid());
        self::assertResponseStatusCodeSame(303);
        self::assertEmailCount(2);
        $location = $this->client->getResponse()->headers->get('Location');

        $this->post(self::SLUG, InquiryFormFields::valid(['email' => 'Anna@Example.ch']));

        self::assertResponseStatusCodeSame(303);
        $this->assertPrivate();
        self::assertSame($location, $this->client->getResponse()->headers->get('Location'));
        self::assertEmailCount(0);
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('anna@example.ch', $rows[0]['email']);
        self::assertSame([$rows[0]['id']], $received->ids);
    }

    public function test_another_inquiry_of_the_guest_is_stored_and_mailed(): void
    {
        DetailVenues::full($this->em);
        DetailVenues::full($this->em, 'landgasthof-linde', 'Landgasthof Linde');
        $received = $this->listen();

        $this->post(self::SLUG, InquiryFormFields::valid());
        $this->post(self::SLUG, InquiryFormFields::valid(['message' => 'Eine andere Frage.']));
        self::assertResponseStatusCodeSame(303);
        self::assertEmailCount(2);
        $this->post('landgasthof-linde', InquiryFormFields::valid());
        self::assertResponseStatusCodeSame(303);
        self::assertEmailCount(2);

        self::assertCount(3, $this->rows());
        self::assertCount(3, $received->ids);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function typos(): iterable
    {
        yield 'no at sign' => ['anna.example.ch'];
        yield 'a space' => ['a b@example.ch'];
        yield 'an umlaut, which only the second rule refuses' => ['müller@example.ch'];
    }

    #[DataProvider('typos')]
    public function test_a_typo_in_the_address_shows_the_error_once(string $email): void
    {
        DetailVenues::full($this->em);

        $crawler = $this->post(self::SLUG, InquiryFormFields::valid(['email' => $email]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Bitte geben Sie eine gültige E-Mail-Adresse an.', trim($crawler->filter('#inquiry_email_error')->text()));
    }

    public function test_an_invalid_form_keeps_the_values_and_links_the_errors(): void
    {
        DetailVenues::full($this->em);
        $received = $this->listen();

        $crawler = $this->post(self::SLUG, InquiryFormFields::valid(['firstName' => '', 'email' => 'keine-adresse', 'message' => 'Meine Nachricht']));

        self::assertResponseStatusCodeSame(422);
        $this->assertPrivate();
        self::assertSelectorExists('#anfrage [role="alert"]');
        self::assertSame('true', $crawler->filter('#inquiry_firstName')->attr('aria-invalid'));
        self::assertSame('inquiry_firstName_error', $crawler->filter('#inquiry_firstName')->attr('aria-describedby'));
        self::assertSelectorTextContains('#inquiry_firstName_error', 'Bitte geben Sie Ihren Vornamen an.');
        self::assertSelectorTextContains('#inquiry_email_error', 'Bitte geben Sie eine gültige E-Mail-Adresse an.');
        self::assertSame('keine-adresse', $crawler->filter('#inquiry_email')->attr('value'));
        self::assertSame('Muster', $crawler->filter('#inquiry_lastName')->attr('value'));
        self::assertSame('120', $crawler->filter('#inquiry_guests')->attr('value'));
        self::assertSame('Meine Nachricht', $crawler->filter('#inquiry_message')->text());
        self::assertSelectorTextContains('h1', 'Schloss Seeblick');
        self::assertSelectorNotExists('#inquiry_lastName[aria-invalid]');

        self::assertSame([], $this->rows());
        self::assertEmailCount(0);
        self::assertSame([], $received->ids);
    }

    public function test_a_re_render_carries_a_fresh_fill_time_so_it_can_be_sent_again(): void
    {
        DetailVenues::full($this->em);
        $sent = InquiryFormFields::tokenFrom('-60 seconds');

        $crawler = $this->post(self::SLUG, InquiryFormFields::valid(['firstName' => '', 'started' => $sent]));

        self::assertNotSame($sent, $crawler->filter('input[name="inquiry[started]"]')->attr('value'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function bodiesWithoutTheForm(): iterable
    {
        yield 'empty' => [[]];
        yield 'another form' => [['other' => ['a' => 'b']]];
        yield 'inquiry as a string' => [['inquiry' => 'x']];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('bodiesWithoutTheForm')]
    public function test_a_post_without_the_form_is_a_422(array $body): void
    {
        DetailVenues::full($this->em);

        $this->post(self::SLUG, $body);

        self::assertResponseStatusCodeSame(422);
        $this->assertPrivate();
        self::assertSame([], $this->rows());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedFields(): iterable
    {
        yield 'NUL in the message' => [['message' => "Hallo\0Team"]];
        yield 'NUL in the name' => [['lastName' => "Mus\0ter"]];
        yield 'invalid UTF-8 in the message' => [['message' => "Hallo \xff Team"]];
        yield 'invalid UTF-8 in the name' => [['firstName' => "An\xc3na"]];
        yield 'unknown occasion' => [['occasion' => 'birthday']];
        yield 'no occasion' => [['occasion' => '']];
        yield 'array as guests' => [['guests' => ['1']]];
        yield 'text as guests' => [['guests' => 'viele']];
        yield 'huge guests' => [['guests' => '99999999999999999999999']];
        yield 'text as date' => [['eventDate' => 'morgen']];
        yield 'impossible date' => [['eventDate' => '2030-02-31']];
        yield 'array as honeypot' => [['website' => ['x']]];
        yield 'array as fill time' => [['started' => ['x']]];
        yield 'umlaut in the local part' => [['email' => 'müller@example.ch']];
        yield 'em space in the local part' => [['email' => "an\u{2003}na@example.ch"]];
        yield 'em space in the domain' => [['email' => "anna@exam\u{2003}ple.ch"]];
        yield 'no-break space in the local part' => [['email' => "an\u{A0}na@example.ch"]];
        yield 'no-break space in the domain' => [['email' => "anna@exam\u{A0}ple.ch"]];
        yield 'ideographic space in the domain' => [['email' => "anna@exam\u{3000}ple.ch"]];
        yield 'line separator in the local part' => [['email' => "an\u{2028}na@example.ch"]];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('malformedFields')]
    public function test_malformed_input_is_a_422_and_never_a_500(array $overrides): void
    {
        DetailVenues::full($this->em);

        $this->post(self::SLUG, InquiryFormFields::valid($overrides));

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->rows());
    }

    /**
     * The same input used to end in `chk_inquiries_email`, whose error logs the whole row.
     */
    public function test_an_address_the_check_refuses_is_a_field_error_and_nothing_reaches_the_log(): void
    {
        DetailVenues::full($this->em);
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->enableDebug();

        $crawler = $this->post(self::SLUG, InquiryFormFields::valid([
            'email' => "mar\u{2003}ker@example.ch",
            'firstName' => 'Markervorname',
            'lastName' => 'Markername',
            'phone' => '044 987 65 43',
            'message' => 'Markertext',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#inquiry_email_error', 'Bitte geben Sie eine gültige E-Mail-Adresse an.');
        self::assertSame("mar\u{2003}ker@example.ch", $crawler->filter('#inquiry_email')->attr('value'));
        self::assertSame([], $this->rows());
        self::assertEmailCount(0);

        $log = print_r($logger->getLogs(), true);
        foreach (['ker@example.ch', 'Markervorname', 'Markername', '987 65 43', 'Markertext'] as $personal) {
            self::assertStringNotContainsString($personal, $log);
        }
    }

    public function test_a_filled_honeypot_gets_the_confirmation_and_nothing_happens(): void
    {
        DetailVenues::full($this->em);
        $received = $this->listen();

        $this->post(self::SLUG, InquiryFormFields::valid(['website' => 'https://spam.example']));

        self::assertResponseStatusCodeSame(303);
        $this->assertPrivate();
        self::assertStringContainsString('anfrage=gesendet', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame([], $this->rows());
        self::assertEmailCount(0);
        self::assertSame([], $received->ids);

        $this->client->followRedirect();
        self::assertSelectorTextContains('#anfrage h2', 'Danke');
    }

    public function test_a_submission_within_three_seconds_is_refused(): void
    {
        DetailVenues::full($this->em);

        $crawler = $this->post(self::SLUG, InquiryFormFields::valid(['started' => InquiryFormFields::tokenFrom('now')]));

        self::assertResponseStatusCodeSame(422);
        $this->assertPrivate();
        self::assertSelectorTextContains('#anfrage [role="alert"]', 'Das ging sehr schnell.');
        self::assertSame('anna@example.ch', $crawler->filter('#inquiry_email')->attr('value'));
        self::assertSame([], $this->rows());
        self::assertEmailCount(0);
    }

    public function test_a_tampered_fill_time_is_refused(): void
    {
        DetailVenues::full($this->em);

        $this->post(self::SLUG, InquiryFormFields::valid(['started' => (time() - 60).'.'.str_repeat('a', 43)]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->rows());
    }

    public function test_the_sixth_valid_submission_within_an_hour_is_rate_limited(): void
    {
        DetailVenues::full($this->em);
        for ($i = 1; $i <= 5; ++$i) {
            $this->post(self::SLUG, InquiryFormFields::valid(['message' => 'Anfrage '.$i]));
            self::assertResponseStatusCodeSame(303);
        }

        $crawler = $this->post(self::SLUG, InquiryFormFields::valid(['lastName' => 'Sechste']));

        self::assertResponseStatusCodeSame(429);
        $this->assertPrivate();
        self::assertMatchesRegularExpression('/^\d+$/', (string) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertSelectorTextContains('#anfrage [role="alert"]', 'mehrere Anfragen');
        self::assertSame('Sechste', $crawler->filter('#inquiry_lastName')->attr('value'));
        self::assertCount(5, $this->rows());
    }

    public function test_invalid_submissions_do_not_count_against_the_limit(): void
    {
        DetailVenues::full($this->em);
        for ($i = 1; $i <= 6; ++$i) {
            $this->post(self::SLUG, InquiryFormFields::valid(['firstName' => '']));
            self::assertResponseStatusCodeSame(422);
        }

        $this->post(self::SLUG, InquiryFormFields::valid());

        self::assertResponseStatusCodeSame(303);
    }

    public function test_a_cross_site_post_is_refused(): void
    {
        DetailVenues::full($this->em);

        $this->post(self::SLUG, InquiryFormFields::valid(), ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->rows());
        self::assertEmailCount(0);
    }

    public function test_a_past_date_is_an_error_on_the_field(): void
    {
        DetailVenues::full($this->em);

        $crawler = $this->post(self::SLUG, InquiryFormFields::valid(['eventDate' => '2020-01-01']));

        self::assertResponseStatusCodeSame(422);
        $this->assertPrivate();
        self::assertSelectorTextContains('#inquiry_eventDate_error', 'in der Vergangenheit');
        self::assertSame('2020-01-01', $crawler->filter('#inquiry_eventDate')->attr('value'));
        self::assertSame([], $this->rows());
    }

    public function test_an_unknown_slug_is_not_found(): void
    {
        $this->post('gibt-es-nicht', InquiryFormFields::valid());

        self::assertResponseStatusCodeSame(404);
        $this->assertPrivate();
    }

    public function test_an_unpublished_venue_is_gone(): void
    {
        $venue = DetailVenues::full($this->em);
        $venue->unpublish();
        $this->em->flush();

        $this->post(self::SLUG, InquiryFormFields::valid());

        self::assertResponseStatusCodeSame(410);
        $this->assertPrivate();
        self::assertSame([], $this->rows());
    }

    public function test_a_venue_without_an_address_is_not_found(): void
    {
        DetailVenues::minimal();

        $this->post(DetailVenues::MINIMAL_SLUG, InquiryFormFields::valid());

        self::assertResponseStatusCodeSame(404);
        $this->assertPrivate();
        self::assertSame([], $this->rows());
    }

    public function test_a_history_slug_is_accepted_and_ends_on_the_current_slug(): void
    {
        $venue = DetailVenues::full($this->em);
        VenueSlugHistoryFactory::createOne(['venue' => $venue, 'slug' => 'altes-schloss']);

        $this->post('altes-schloss', InquiryFormFields::valid());

        self::assertResponseStatusCodeSame(303);
        self::assertStringContainsString('/hochzeitslocation/'.self::SLUG.'?', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame(self::SLUG, $this->rows()[0]['venue_slug']);
    }

    public function test_get_is_not_allowed(): void
    {
        DetailVenues::full($this->em);

        $this->client->request('GET', '/hochzeitslocation/'.self::SLUG.'/anfrage');

        self::assertResponseStatusCodeSame(405);
    }

    public function test_a_throwing_subscriber_does_not_reach_the_guest(): void
    {
        DetailVenues::full($this->em);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(InquirySubmitted::class, static function (): never {
            throw new \RuntimeException('The CRM is down.');
        });

        $this->post(self::SLUG, InquiryFormFields::valid());

        self::assertResponseStatusCodeSame(303);
        self::assertCount(1, $this->rows());
    }

    public function test_a_stored_inquiry_reaches_the_crm(): void
    {
        DetailVenues::full($this->em);

        $this->post(self::SLUG, InquiryFormFields::valid());

        self::assertResponseStatusCodeSame(303);
        $link = $this->connection->fetchAssociative('SELECT inquiry_id, status FROM crm_inquiry_links');
        self::assertSame(['inquiry_id' => $this->rows()[0]['id'], 'status' => 'new'], $link);
        // A couple is no CRM contact since #55: the inquiry and its link row are the record.
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_contacts WHERE email = ?', ['anna@example.ch']));
    }

    public function test_the_honeypot_writes_nothing_to_the_crm(): void
    {
        DetailVenues::full($this->em);

        $this->post(self::SLUG, InquiryFormFields::valid(['website' => 'https://spam.example']));

        self::assertResponseStatusCodeSame(303);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_contacts'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_inquiry_links'));
    }

    /**
     * Without its type row the flush fails on a foreign key, which closes the EntityManager. The guest sees none of it,
     * and no link row is what lets the nightly backfill pick the inquiry up. The venue has a Location, because without
     * one the ingestion writes only the link row and no entry (#55 D5), so nothing would fail.
     */
    public function test_a_failing_ingestion_does_not_reach_the_guest_and_leaves_the_inquiry(): void
    {
        CompanyFactory::new()->withVenue(DetailVenues::full($this->em))->create();
        $this->connection->executeStatement("DELETE FROM crm_activity_types WHERE code = 'inquiry'");

        $this->post(self::SLUG, InquiryFormFields::valid());

        self::assertResponseStatusCodeSame(303);
        self::assertStringContainsString('anfrage=gesendet', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertCount(1, $this->rows());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_inquiry_links'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_contacts'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_activities'));
    }
}
