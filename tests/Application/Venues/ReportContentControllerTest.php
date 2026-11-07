<?php

declare(strict_types=1);

namespace App\Tests\Application\Venues;

use App\Factory\VenuePhotoFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * `/inhalt-melden` (G2) as a visitor meets it: the form with and without a venue, the checks of a submit (form,
 * honeypot, limiter), the two mails, the signed confirmation, and the links from the footer and the detail page.
 * The client shares the test's connection, so everything is rolled back.
 */
final class ReportContentControllerTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $pool = self::getContainer()->get('cache.content_report');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_without_a_venue_the_form_asks_which_location_is_meant(): void
    {
        $crawler = $this->client->request('GET', '/inhalt-melden');

        self::assertResponseIsSuccessful();
        $this->assertPrivate();
        self::assertSame('Inhalt melden', $crawler->filter('h1')->text());
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertCount(1, $crawler->filter('input[name="report[venueReference]"]'));
        self::assertCount(0, $crawler->filter('input[name="report[photos][]"]'));
        self::assertSame(['rights', 'removal', 'correction'], $crawler->filter('input[name="report[kind]"]')->each(static fn ($radio): string => (string) $radio->attr('value')));
    }

    public function test_with_a_published_venue_the_form_names_it_and_offers_its_public_photos(): void
    {
        $venue = DetailVenues::minimal();
        $public = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $private = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 2]);

        $crawler = $this->client->request('GET', '/inhalt-melden?location='.$venue->number(), server: ['HTTP_REFERER' => 'http://localhost/hochzeitslocation/'.DetailVenues::MINIMAL_SLUG]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Gartenhaus Minimal', $crawler->filter('.report__venue')->text());
        self::assertCount(0, $crawler->filter('input[name="report[venueReference]"]'));
        self::assertSame([$public->id()], $crawler->filter('input[name="report[photos][]"]')->each(static fn ($box): string => (string) $box->attr('value')));
        self::assertStringNotContainsString($private->id(), (string) $this->client->getResponse()->getContent());
        self::assertSame('lazy', $crawler->filter('.report__photo img')->attr('loading'));
        self::assertSame('/hochzeitslocation/'.DetailVenues::MINIMAL_SLUG, $crawler->filter('input[name="report[source]"]')->attr('value'));
    }

    public function test_an_unknown_unpublished_or_malformed_location_shows_the_form_without_a_venue(): void
    {
        $draft = DetailVenues::minimal();
        $this->connection->executeStatement('UPDATE venues SET status = ? WHERE id = ?', [VenueStatus::Unpublished->value, $draft->id()]);

        foreach ([(string) $draft->number(), '99999', 'abc', '-1', '0', '99999999999999999999'] as $location) {
            $crawler = $this->client->request('GET', '/inhalt-melden?location='.urlencode($location));
            self::assertResponseIsSuccessful($location);
            self::assertCount(1, $crawler->filter('input[name="report[venueReference]"]'), $location);
            self::assertCount(0, $crawler->filter('.report__venue'), $location);
        }
    }

    public function test_a_valid_complaint_is_stored_mailed_twice_and_confirmed_on_a_signed_page(): void
    {
        $this->post('/inhalt-melden', $this->fields(['venueReference' => 'Gasthaus zur Linde, Bern', 'kind' => 'correction']));

        self::assertResponseStatusCodeSame(303);
        $this->assertPrivate();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('gesendet=1', $location);
        self::assertStringContainsString('_hash=', $location);
        self::assertEmailCount(2);
        $mails = array_values(array_filter(self::getMailerMessages(), static fn ($message): bool => $message instanceof Email));
        $byRecipient = [];
        foreach ($mails as $mail) {
            $byRecipient[$mail->getTo()[0]->getAddress()] = $mail;
        }
        $staff = $this->staffAddress();
        self::assertSame(['anna@example.ch', $staff], array_keys($byRecipient));
        $receipt = (string) $byRecipient['anna@example.ch']->getHtmlBody();
        self::assertStringContainsString('Danke für deine Meldung', $receipt);
        self::assertStringNotContainsString('Anna Muster', $receipt, 'the receipt quotes nothing the visitor typed');
        self::assertStringNotContainsString('Gasthaus zur Linde', $receipt);
        self::assertNotNull($byRecipient['anna@example.ch']->getTextBody());
        $notice = (string) $byRecipient[$staff]->getHtmlBody();
        self::assertStringContainsString('Gasthaus zur Linde, Bern', $notice);
        self::assertStringContainsString('Die Öffnungszeiten stimmen nicht.', $notice);
        self::assertSame('anna@example.ch', $byRecipient[$staff]->getReplyTo()[0]->getAddress());

        self::assertSame(
            ['kind' => 'correction', 'venue_id' => null, 'venue_reference' => 'Gasthaus zur Linde, Bern', 'complainant_name' => 'Anna Muster', 'status' => 'open'],
            $this->connection->fetchAssociative('SELECT kind, venue_id, venue_reference, complainant_name, status FROM moderation_cases'),
        );
        self::assertSame('system', $this->connection->fetchOne("SELECT actor_type FROM admin_audit_log WHERE subject_type = 'moderation_case'"));

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('Danke, deine Meldung ist eingegangen.', $crawler->filter('.report__sent h2')->text());
        self::assertStringContainsString('Gemeldete Fotos bleiben verborgen', $crawler->filter('.report__sent')->text());
        self::assertCount(0, $crawler->filter('form.report__form'));

        $crawler = $this->client->request('GET', '/inhalt-melden?gesendet=1');
        self::assertCount(1, $crawler->filter('form.report__form'), 'an unsigned confirmation is the form');
    }

    public function test_an_invalid_complaint_is_answered_with_its_errors_and_stores_nothing(): void
    {
        $crawler = $this->post('/inhalt-melden', $this->fields(['kind' => '', 'message' => 'kurz', 'goodFaith' => null, 'email' => 'anna.example.ch', 'venueReference' => '']));

        self::assertResponseStatusCodeSame(422);
        $this->assertPrivate();
        $errors = $crawler->filter('.report__error')->each(static fn ($error): string => $error->text());
        self::assertContains('Bitte wähle, worum es geht.', $errors);
        self::assertContains('Bitte gib an, welche Location du meinst.', $errors);
        self::assertContains('Bitte beschreib dein Anliegen in mindestens 10 Zeichen.', $errors);
        self::assertContains('Bitte gib eine gültige E-Mail-Adresse an.', $errors);
        self::assertContains('Bitte bestätige, dass deine Angaben stimmen.', $errors);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM moderation_cases'));
        self::assertEmailCount(0);
    }

    public function test_a_crafted_photo_id_or_source_is_refused(): void
    {
        $venue = DetailVenues::minimal();
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);

        $this->post('/inhalt-melden?location='.$venue->number(), $this->fields(['photos' => ['019a0000-0000-7000-8000-000000000999']]));
        self::assertResponseStatusCodeSame(422);

        $this->post('/inhalt-melden', $this->fields(['venueReference' => 'Irgendwo', 'source' => '//evil.example/x']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM moderation_cases'));
    }

    public function test_a_filled_honeypot_gets_the_same_confirmation_and_nothing_is_stored(): void
    {
        $this->post('/inhalt-melden', $this->fields(['venueReference' => 'Irgendwo', 'website' => 'https://spam.example']));

        self::assertResponseStatusCodeSame(303);
        self::assertStringContainsString('gesendet=1', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM moderation_cases'));
        self::assertEmailCount(0);
    }

    public function test_the_sixth_complaint_of_an_hour_is_refused_with_429(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->post('/inhalt-melden', $this->fields(['venueReference' => 'Ort '.$i]));
            self::assertResponseStatusCodeSame(303, (string) $i);
        }

        $crawler = $this->post('/inhalt-melden', $this->fields(['venueReference' => 'Ort 6']));

        self::assertResponseStatusCodeSame(429);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertStringContainsString('schon mehrere Meldungen', $crawler->filter('.report__summary')->text());
        self::assertSame(5, (int) $this->connection->fetchOne('SELECT count(*) FROM moderation_cases'));
    }

    public function test_the_footer_and_the_detail_page_link_to_the_form(): void
    {
        $venue = DetailVenues::minimal();

        $crawler = $this->client->request('GET', '/hochzeitslocation/'.DetailVenues::MINIMAL_SLUG);

        self::assertSame('/inhalt-melden?location='.$venue->number(), $crawler->filter('.venue-report a')->attr('href'));
        self::assertCount(1, $crawler->filter('.site-footer a[href="/inhalt-melden"]'));
    }

    /**
     * @param array<string, mixed> $overrides a null value drops the field
     *
     * @return array{report: array<string, mixed>}
     */
    private function fields(array $overrides = []): array
    {
        $fields = array_replace([
            'kind' => 'rights',
            'message' => 'Die Öffnungszeiten stimmen nicht.',
            'name' => 'Anna Muster',
            'email' => 'anna@example.ch',
            'goodFaith' => '1',
            'source' => '',
            'website' => '',
            '_token' => 'csrf-token',
        ], $overrides);

        return ['report' => array_filter($fields, static fn (mixed $value): bool => null !== $value)];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function post(string $url, array $fields): Crawler
    {
        return $this->client->request('POST', $url, $fields, [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
    }

    private function staffAddress(): string
    {
        $address = self::getContainer()->getParameter('site.contact_email');
        self::assertIsString($address);

        return $address;
    }

    private function assertPrivate(): void
    {
        $response = $this->client->getResponse();
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame([], $response->headers->getCookies());
    }
}
