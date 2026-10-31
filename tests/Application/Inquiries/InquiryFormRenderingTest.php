<?php

declare(strict_types=1);

namespace App\Tests\Application\Inquiries;

use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
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

    private const PAGE = '/hochzeitslocation/'.DetailVenues::FULL_SLUG;

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
        self::assertSame(self::PAGE.'/anfrage#anfrage', $form->attr('action'));
        self::assertSelectorTextContains('#anfrage h2', 'Schloss Seeblick kontaktieren');
        self::assertSame(['Kostenlos', 'Unverbindlich', 'Direkt zur Location'], $crawler->filter('.inquiry-form__promises li')->each(static fn (Crawler $li): string => $li->text()));

        foreach (['occasion', 'guests', 'eventDate', 'message', 'firstName', 'lastName', 'email', 'phone'] as $field) {
            self::assertCount(1, $crawler->filter(\sprintf('#anfrage label[for="inquiry_%s"]', $field)), $field);
            self::assertCount(1, $crawler->filter('#anfrage #inquiry_'.$field), $field);
        }
        self::assertSelectorTextContains('#anfrage button[type="submit"]', 'Anfragen');
        self::assertSame('/datenschutz', $crawler->filter('#anfrage .inquiry-form__privacy a')->attr('href'));

        self::assertSame('wedding', $crawler->filter('#inquiry_occasion option[selected]')->attr('value'));
        self::assertSame(['Hochzeit', 'Standesamtliche Trauung', 'Hochzeitsapéro', 'Verlobungsfeier', 'Anderer Anlass'], $crawler->filter('#inquiry_occasion option')->each(static fn (Crawler $option): string => $option->text()));
        self::assertStringStartsWith('Liebes Schloss Seeblick-Team', $crawler->filter('#inquiry_message')->text(normalizeWhitespace: false));
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

    public function test_the_form_adds_no_query(): void
    {
        DetailVenues::full($this->em);
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        $holder->reset();

        $this->page(self::PAGE);

        self::assertCount(5, $holder->getData()['default']);
    }

    public function test_a_venue_without_an_address_has_an_empty_section_and_no_primary_link(): void
    {
        DetailVenues::minimal();

        $crawler = $this->page('/hochzeitslocation/'.DetailVenues::MINIMAL_SLUG);

        self::assertCount(1, $crawler->filter('section#anfrage'));
        self::assertSame('', $crawler->filter('section#anfrage')->html());
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
        $venue = DetailVenues::minimal();
        $venue->changeContact('+41441234567', null, null, null);
        $this->em->flush();

        $crawler = $this->page('/hochzeitslocation/'.DetailVenues::MINIMAL_SLUG);

        self::assertSame('#kontakt', $crawler->filter('.venue-actions__primary')->attr('href'));
        self::assertCount(1, $crawler->filter('#kontakt'));
        self::assertSame('', $crawler->filter('section#anfrage')->html());
    }

    public function test_a_signed_flag_shows_the_confirmation(): void
    {
        DetailVenues::full($this->em);

        $crawler = $this->page($this->signed(self::PAGE.'?anfrage=gesendet'));

        self::assertSelectorNotExists('#anfrage form');
        self::assertSelectorTextContains('#anfrage h2', 'Danke');
        self::assertSelectorTextContains('#anfrage', 'Schloss Seeblick');
        self::assertSame(self::PAGE.'#anfrage', $crawler->filter('#anfrage a')->attr('href'));
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));
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
        $signed = $this->signed('/hochzeitslocation/andere-location?anfrage=gesendet');
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
