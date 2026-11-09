<?php

declare(strict_types=1);

namespace App\Tests\Application\Inquiries;

use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Inquiries\InquiryFormFields;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\ValueObject\KnownTiers;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\UriSigner;
use Zenstruck\Foundry\Test\Factories;

/**
 * The form on the cached detail page: the same HTML for every visitor, no session, no extra
 * query (S10). The signed flag switches to the confirmation.
 */
final class InquiryFormRenderingTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const PAGE = '/eventlokal/'.DetailVenues::FULL_SLUG;

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
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_the_form_has_every_field_of_dp_13(): void
    {
        DetailVenues::full($this->em);

        $crawler = $this->page(self::PAGE);

        $form = $crawler->filter('#anfrage form');
        self::assertCount(1, $form);
        self::assertSame('post', strtolower((string) $form->attr('method')));
        self::assertSame(self::PAGE.'/anfrage#anfrage-hinweis', $form->attr('action'), 'a re-render opens at its focusable summary');
        self::assertSelectorTextContains('#anfrage h2', 'Schloss Seeblick kontaktieren');
        self::assertSame(['Kostenlos', 'Unverbindlich', 'Direkt zur Location'], $crawler->filter('.inquiry-form__promises li')->each(static fn (Crawler $li): string => $li->text()));

        foreach (['eventType', 'guests', 'eventDate', 'dateFlexible', 'startTime', 'cateringWish', 'budgetChf', 'message', 'firstName', 'lastName', 'email', 'phone', 'company'] as $field) {
            self::assertCount(1, $crawler->filter(\sprintf('#anfrage label[for="inquiry_%s"]', $field)), $field);
            self::assertCount(1, $crawler->filter('#anfrage #inquiry_'.$field), $field);
        }
        self::assertSelectorTextContains('#anfrage button[type="submit"]', 'Anfragen');
        self::assertSame('/datenschutz', $crawler->filter('#anfrage .inquiry-form__privacy a')->attr('href'));

        // DP-13: no default event type; the choices are the 17 `suitable_for` terms (F3).
        $eventTypes = $crawler->filter('#inquiry_eventType option');
        self::assertCount(18, $eventTypes);
        self::assertSame(['', 'Bitte wählen'], [$eventTypes->eq(0)->attr('value'), $eventTypes->eq(0)->text()]);
        self::assertSame(['corporate_event', 'Firmenanlass'], [$eventTypes->eq(1)->attr('value'), $eventTypes->eq(1)->text()]);
        self::assertSame('', $crawler->filter('#inquiry_eventType option[selected]')->attr('value'));
        self::assertSame('required', $crawler->filter('#inquiry_eventType')->attr('required'));
        self::assertSame(
            [['', 'Noch offen'], ['in_house', 'Hauseigenes Catering'], ['external_allowed', 'Externes Catering erlaubt']],
            $crawler->filter('#inquiry_cateringWish option')->each(static fn (Crawler $option): array => [(string) $option->attr('value'), $option->text()]),
        );
        self::assertSame('checkbox', $crawler->filter('#inquiry_dateFlexible')->attr('type'));
        self::assertSame('Datum ist flexibel', $crawler->filter('label[for="inquiry_dateFlexible"]')->text());
        self::assertSame('time', $crawler->filter('#inquiry_startTime')->attr('type'));
        self::assertSame('organization', $crawler->filter('#inquiry_company')->attr('autocomplete'));
        $message = $crawler->filter('#inquiry_message')->text(normalizeWhitespace: false);
        self::assertStringStartsWith('Liebes Schloss Seeblick-Team', $message);
        self::assertStringNotContainsString('Hochzeit', $message);
        self::assertNotNull($crawler->filter('#inquiry_message')->attr('data-inquiry-message'), 'inquiry.js moves its caret (F16 N4)');
        self::assertCount(1, $crawler->filter('script[src*="/js/inquiry"]'));
    }

    public function test_the_spam_fields_are_there_and_the_honeypot_is_out_of_the_tab_order(): void
    {
        DetailVenues::full($this->em);

        $crawler = $this->page(self::PAGE);

        self::assertSame('csrf-token', $crawler->filter('input[name="inquiry[_token]"]')->attr('value'));
        self::assertMatchesRegularExpression('/^\d+\.[A-Za-z0-9_-]{43}$/', (string) $crawler->filter('input[name="inquiry[started]"]')->attr('value'));
        $honeypot = $crawler->filter('.inquiry-form__trap input[name="inquiry[website]"]');
        self::assertSame('-1', $honeypot->attr('tabindex'));
        self::assertSame('off', $honeypot->attr('autocomplete'));
        self::assertSame('Dieses Feld bitte leer lassen', $crawler->filter('.inquiry-form__trap label')->text());
    }

    public function test_the_page_stays_public_and_the_same_for_every_visitor(): void
    {
        DetailVenues::full($this->em);

        $first = $this->withoutFillTime($this->html(self::PAGE));
        $response = $this->client->getResponse();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('s-maxage=86400', $cacheControl);
        self::assertStringNotContainsString('private', $cacheControl);
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertSame([], $response->headers->getCookies());

        self::assertSame($first, $this->withoutFillTime($this->html(self::PAGE)));
    }

    /**
     * The form reads the vocabulary for "Anlass" and "Catering" (F9) in one query; nothing else of it touches the
     * database.
     */
    public function test_the_form_adds_one_query_for_its_vocabulary(): void
    {
        DetailVenues::full($this->em);
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        $holder->reset();

        $this->page(self::PAGE);

        // The slug, the venue (row, rooms, photos and the plan's limits, F12), the similar venues and D18's operator check (G6, `Venues\VenueClaims`); the form adds one, its event types and catering wishes (F9).
        $queries = $holder->getData()['default'];
        self::assertCount(8, $queries);
        self::assertCount(1, array_filter($queries, static fn (array $query): bool => str_contains((string) $query['sql'], 'taxonomy_terms WHERE term_group = ')));
    }

    public function test_a_venue_without_an_address_has_no_section_and_no_link_to_it(): void
    {
        // Defensive since D60: no publish path leaves a venue online without an inquiry address (F16 kept the path).
        DetailVenues::minimal();

        $crawler = $this->page('/eventlokal/'.DetailVenues::MINIMAL_SLUG);

        self::assertCount(0, $crawler->filter('#anfrage'));
        self::assertCount(0, $crawler->filter('a[href*="#anfrage"]'));
        self::assertSelectorNotExists('.venue-actions__primary');
    }

    public function test_the_primary_link_goes_to_the_form(): void
    {
        DetailVenues::full($this->em);

        $crawler = $this->page(self::PAGE);

        self::assertSame('#anfrage', $crawler->filter('.venue-actions__primary')->attr('href'));
    }

    public function test_the_primary_link_goes_to_kontakt_without_an_address(): void
    {
        // On Pro: Free hides the phone, and then there is no Kontakt section to link to (F12).
        $venue = DetailVenues::minimal(tier: KnownTiers::PRO);
        $venue->changeContact('+41441234567', null, null, null);
        $this->em->flush();

        $crawler = $this->page('/eventlokal/'.DetailVenues::MINIMAL_SLUG);

        self::assertSame('#kontakt', $crawler->filter('.venue-actions__primary')->attr('href'));
        self::assertCount(1, $crawler->filter('#kontakt'));
        self::assertCount(0, $crawler->filter('#anfrage'));
    }

    public function test_a_signed_flag_shows_the_confirmation(): void
    {
        DetailVenues::full($this->em);

        $crawler = $this->page($this->signed(self::PAGE.'?anfrage=gesendet'));

        self::assertSelectorNotExists('#anfrage form');
        self::assertSelectorExists('.inquiry-form--sent .inquiry-form__sent-icon');
        self::assertSelectorTextContains('#anfrage h2', 'Danke');
        self::assertSame('-1', $crawler->filter('#anfrage h2#anfrage-gesendet')->attr('tabindex'), 'the redirect lands on it and it takes the focus (F16 N5)');
        self::assertNotNull($crawler->filter('#anfrage h2')->attr('data-inquiry-focus'), 'inquiry.js focuses it on load');
        self::assertCount(1, $crawler->filter('[data-inquiry-focus]'));
        self::assertCount(1, $crawler->filter('script[src*="/js/inquiry"]'));
        self::assertSelectorTextContains('#anfrage', 'Schloss Seeblick');
        self::assertSame(self::PAGE.'#anfrage', $crawler->filter('#anfrage a')->attr('href'));
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));
    }

    public function test_an_invalid_post_shows_the_summary_with_its_icon(): void
    {
        DetailVenues::full($this->em);

        $this->client->request('POST', self::PAGE.'/anfrage', InquiryFormFields::valid(['firstName' => '']), [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.inquiry-form__summary svg');
    }

    public function test_an_unsigned_flag_shows_the_form(): void
    {
        DetailVenues::full($this->em);

        $this->page(self::PAGE.'?anfrage=gesendet');

        self::assertSelectorExists('#anfrage form');
    }

    public function test_a_tampered_flag_shows_the_form(): void
    {
        DetailVenues::full($this->em);

        $this->page($this->signed(self::PAGE.'?anfrage=gesendet').'x');

        self::assertSelectorExists('#anfrage form');
    }

    public function test_a_flag_signed_for_another_venue_shows_the_form(): void
    {
        DetailVenues::full($this->em);
        $signed = $this->signed('/eventlokal/andere-location?anfrage=gesendet');
        $query = (string) parse_url($signed, \PHP_URL_QUERY);

        $this->page(self::PAGE.'?'.$query);

        self::assertSelectorExists('#anfrage form');
    }

    public function test_an_array_flag_is_ignored(): void
    {
        DetailVenues::full($this->em);

        $this->page(self::PAGE.'?anfrage[]=x');

        self::assertSelectorExists('#anfrage form');
    }

    private function page(string $uri): Crawler
    {
        $crawler = $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function html(string $uri): string
    {
        $this->page($uri);

        return (string) $this->client->getResponse()->getContent();
    }

    private function withoutFillTime(string $html): string
    {
        $stripped = (string) preg_replace('/\d+\.[A-Za-z0-9_-]{43}/', '{token}', $html);
        self::assertStringContainsString('{token}', $stripped);

        return $stripped;
    }

    private function signed(string $path): string
    {
        $signer = self::getContainer()->get('uri_signer');
        self::assertInstanceOf(UriSigner::class, $signer);

        return $signer->sign('http://localhost'.$path);
    }
}
