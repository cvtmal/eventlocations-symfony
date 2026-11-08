<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use App\Favorites\ValueObject\MerklisteCookie;
use App\Shared\Controller\LegalController;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Command\EraseModerationComplainants;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Impressum and Datenschutz (#18): the Q5 facts verbatim, cached like every public page, linked
 * from the footer, and a text that names nothing the site does not do.
 */
final class LegalPagesTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const array PAGES = ['/impressum', '/datenschutz'];

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

    public function test_the_impressum_shows_the_operator_exactly(): void
    {
        $crawler = $this->page('/impressum');

        $text = $crawler->filter('main')->text();
        foreach (['Pick n Roll GmbH', 'Sonneggstrasse 61', '8006 Zürich', 'UID-Nr.: CHE-134.034.546 MWST', 'hallo@deine-eventlocation.ch'] as $fact) {
            self::assertStringContainsString($fact, $text);
        }
        self::assertCount(1, $crawler->filter('main a[href="mailto:hallo@deine-eventlocation.ch"]'));
        self::assertCount(0, $crawler->filter('main a[href^="tel:"]'));
    }

    public function test_the_datenschutz_page_has_every_section(): void
    {
        $crawler = $this->page('/datenschutz');

        self::assertSame([
            'Verantwortliche Stelle',
            'Anfragen an eine Location',
            'Kontakte von Locations',
            'Meldungen zu Inhalten',
            'Merkliste und Cookie',
            'Statistik',
            'Schutz vor Missbrauch',
            'Hosting und Datenbank',
            'Karten und Bewertungen von Google Maps',
            'Links zu anderen Anbietern',
            'Interner Bereich',
            'Deine Rechte',
        ], $crawler->filter('main h2')->each(static fn (Crawler $h2): string => $h2->text()));
        self::assertStringContainsString('Pick n Roll GmbH', $crawler->filter('main address')->text());
    }

    public function test_both_pages_are_cacheable_indexable_and_set_no_cookie(): void
    {
        foreach (self::PAGES as $path) {
            $crawler = $this->page($path);
            $response = $this->client->getResponse();

            $cacheControl = (string) $response->headers->get('Cache-Control');
            foreach (['public', 's-maxage=86400', 'stale-while-revalidate=86400', 'stale-if-error=86400'] as $directive) {
                self::assertStringContainsString($directive, $cacheControl, $path);
            }
            self::assertFalse($response->headers->has('Set-Cookie'), $path);
            self::assertCount(1, $crawler->filter('h1'), $path);
            self::assertSame('http://localhost'.$path, $crawler->filter('link[rel="canonical"]')->attr('href'));
            self::assertNotSame('', trim((string) $crawler->filter('meta[name="description"]')->attr('content')), $path);
            self::assertCount(0, $crawler->filter('meta[name="robots"]'), $path);
        }
    }

    public function test_the_footer_links_both_pages_everywhere(): void
    {
        DetailVenues::full($this->em);

        foreach (['/eventlokale', '/eventlokal/'.DetailVenues::FULL_SLUG, ...self::PAGES] as $path) {
            $crawler = $this->page($path);

            $links = $crawler->filter('footer nav.footer-legal a')->each(static fn (Crawler $a): string => $a->text().' '.$a->attr('href'));
            self::assertSame(['Impressum /impressum', 'Datenschutz /datenschutz'], $links, $path);
        }
    }

    public function test_the_datenschutz_page_covers_the_contacts_of_locations(): void
    {
        $text = $this->page('/datenschutz')->filter('main')->text();

        self::assertStringContainsString('Wenn du für eine Location arbeitest, bearbeiten wir deine geschäftlichen Kontaktdaten', $text);
        self::assertStringContainsString('um die Zusammenarbeit mit den Locations zu pflegen', $text);
        self::assertStringContainsString('Verlangst du die Löschung, löschen wir sie früher', $text);
    }

    public function test_the_datenschutz_page_explains_the_statistics_and_how_to_refuse(): void
    {
        $text = $this->page('/datenschutz')->filter('main')->text();

        self::assertStringContainsString('Wir zählen ohne Cookies und ohne Drittanbieter', $text);
        self::assertStringContainsString('Deine IP-Adresse speichern wir nicht', $text);
        // stats.js sends nothing when navigator.globalPrivacyControl is set (#47).
        self::assertStringContainsString('Global Privacy Control, zählen wir nichts', $text);
    }

    public function test_the_retention_follows_the_purge_parameter(): void
    {
        $months = self::getContainer()->getParameter('inquiries.retention_months');
        self::assertSame(12, $months);

        self::assertStringContainsString('löschen sie 12 Monate nach dem Eingang', $this->page('/datenschutz')->filter('main')->text());
    }

    public function test_the_datenschutz_page_explains_content_reports_word_for_word(): void
    {
        self::assertSame(3, EraseModerationComplainants::RETENTION_YEARS);

        $section = $this->page('/datenschutz')->filter('main section')->reduce(
            static fn (Crawler $section): bool => 'Meldungen zu Inhalten' === $section->filter('h2')->text(),
        );

        self::assertCount(1, $section);
        self::assertSame([
            'Über das Formular «Inhalt melden» kannst du uns ein Foto, einen Eintrag oder falsche Angaben melden, zum Beispiel wenn ein Foto ohne Erlaubnis gezeigt wird. Dafür bearbeiten wir deinen Namen, deine E-Mail-Adresse, deine Nachricht, die Seite, von der aus du meldest, und deine Bestätigung, dass du in gutem Glauben meldest. Mit deiner Meldung speichern wir deine IP-Adresse nicht. Zum Schutz vor Missbrauch halten wir höchstens zwei Stunden lang einen Prüfwert fest, aus dem sich deine IP-Adresse nicht zurückrechnen lässt.',
            'Du erhältst eine Eingangsbestätigung per E-Mail. Ein gemeldetes Foto blenden wir aus, bis wir entschieden haben. Wir prüfen die Meldung, entfernen den Inhalt oder lassen ihn stehen und melden uns bei Bedarf bei dir. Deinen Namen und deine E-Mail-Adresse geben wir nicht an die Location weiter, ausser du bist damit einverstanden.',
            'Wir bewahren eine Meldung auf, solange wir sie bearbeiten, und danach höchstens drei Jahre ab unserem Entscheid, damit wir bei einem Streit über Bildrechte belegen können, was wir wann getan haben. Danach löschen wir deinen Namen, deine E-Mail-Adresse und deine Nachricht.',
        ], $section->filter('p')->each(static fn (Crawler $p): string => $p->text()));
    }

    public function test_the_cookie_lifetime_is_the_merkliste_cookie_s_and_says_up_to(): void
    {
        self::assertSame(MerklisteCookie::MAX_AGE_SECONDS, LegalController::MERKLISTE_DAYS * 86400);

        $text = $this->page('/datenschutz')->filter('main')->text();

        self::assertStringContainsString('bis zu 400 Tage', $text);
        self::assertDoesNotMatchRegularExpression('/(?<!bis zu )400 Tage/', $text);
    }

    public function test_no_page_names_a_service_the_site_does_not_use_or_shows_a_marker(): void
    {
        foreach (self::PAGES as $path) {
            $html = (string) $this->page($path)->html();

            foreach (['Google Analytics', 'Google Fonts', 'Mailchimp', 'TODO'] as $word) {
                self::assertStringNotContainsString($word, $html, $path.': '.$word);
            }
        }
    }

    public function test_google_maps_is_explained_because_the_detail_page_links_to_it(): void
    {
        $text = $this->page('/datenschutz')->filter('main')->text();

        self::assertStringContainsString('Google Maps', $text);
        // The page quotes #15's button and link labels, so it must follow them when they change.
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $load = $translator->trans('detail.location.map.load', [], 'venues', 'de');
        $open = $translator->trans('detail.location.map.open', [], 'venues', 'de');
        self::assertStringContainsString('erst, wenn du auf «'.$load.'» klickst', $text);
        self::assertStringContainsString('«'.$open.'»', $text);
    }

    public function test_the_near_me_page_is_named_in_the_google_maps_section(): void
    {
        $text = $this->page('/datenschutz')->filter('main')->text();
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        // #52 (Q6): the sentence quotes the nav link and the button, so it must follow them.
        self::assertStringContainsString('«'.$translator->trans('nearby.nav', [], 'search', 'de').'»', $text);
        self::assertStringContainsString('«'.$translator->trans('nearby.geo.button', [], 'search', 'de').'»', $text);
        self::assertStringContainsString('sofort beim Aufruf', $text);
        self::assertStringContainsString('Server-Protokollen', $text);
    }

    public function test_the_google_rating_is_explained_and_the_map_clause_names_only_the_map(): void
    {
        $text = $this->page('/datenschutz')->filter('main')->text();

        // #53: the detail page's rating loads on view, so "nothing before the click" now speaks of the map only.
        self::assertStringContainsString('Die Karte laden wir erst, wenn du auf «Karte laden» klickst; für die Karte wird vorher nichts an Google übertragen.', $text);
        self::assertStringNotContainsString('Vorher wird nichts an Google übertragen.', $text);
        self::assertStringContainsString('Auf der Seite einer Location kann die Google-Bewertung erscheinen', $text);
        self::assertStringContainsString('ohne Klick direkt bei Google, sobald dieser Teil der Seite sichtbar wird', $text);
        self::assertStringContainsString('Wir speichern keine Bewertungen von Google.', $text);
    }

    private function page(string $path): Crawler
    {
        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful($path);

        return $crawler;
    }
}
