<?php

declare(strict_types=1);

namespace App\Tests\Application\Seo;

use App\Factory\VenueSlugHistoryFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * `venue_markdown`: the detail page's facts as Markdown, answering as `venue_show` does, never
 * with the phone number or the e-mail address, and linked from the detail page.
 */
final class VenueMarkdownTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const string MD = '/eventlokal/'.DetailVenues::FULL_SLUG.'.md';
    private const string HTML_URL = 'http://localhost/eventlokal/'.DetailVenues::FULL_SLUG;

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

    public function test_a_venue_answers_with_its_markdown_and_headers(): void
    {
        DetailVenues::full($this->em);

        $this->client->request('GET', self::MD);

        self::assertResponseIsSuccessful();
        $headers = $this->client->getResponse()->headers;
        self::assertSame('text/markdown; charset=UTF-8', $headers->get('Content-Type'));
        self::assertSame('noindex', $headers->get('X-Robots-Tag'));
        self::assertSame('<'.self::HTML_URL.'>; rel="canonical"', $headers->get('Link'));
        self::assertNull($headers->get('Set-Cookie'));

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("# Schloss Seeblick\n\n", $body);
        self::assertStringEndsWith("\n---\n\nSeite der Location: ".self::HTML_URL."\n", $body);
        self::assertStringNotContainsString("\n\n\n", $body);
        self::assertSame(
            ['## Ausstattung und Eignung', '## Angaben zur Location', '## Räume', '## Häufige Fragen', '## Standort', '## Kontakt', '## Dokumente'],
            array_values(preg_grep('/^## /', explode("\n", $body)) ?: []),
        );
        self::assertStringContainsString("\n- 50 - 300 Personen\n\nErster Absatz über das Schloss.\n\nZweiter Absatz mit dem Park.\n", $body);
        self::assertStringContainsString("\n- **Geeignet für:** Firmenanlass, Familienfeier, Hochzeit\n", $body);
        self::assertStringContainsString("\n- **Preisniveau:** Mittel\n- **Ambiente:** Romantisch am Wasser\n", $body);
        self::assertStringContainsString("\n- **Sitzplätze und Stehplätze:** 1'200 Sitzplätze; 1'500 Stehplätze; Mit Tanzfläche 900 Sitzplätze.\n", $body);
        self::assertStringContainsString("\n- **Ausstattung:** Tanzfläche\n- **Catering:** Vegetarisch möglich, Glutenfrei möglich\n- **Sprachen:** Deutsch, Englisch\n", $body);
        self::assertStringContainsString("\n- **Öffnungszeiten:** Mai bis Oktober, täglich ab 10 Uhr\n", $body);
        // Removed with the wedding facts (F3): no party, curfew, ceremony or vendor line.
        self::assertStringNotContainsString('Sperrstunde', $body);
        self::assertStringNotContainsString('Trauung', $body);
        self::assertStringNotContainsString('Dienstleister', $body);
        self::assertStringContainsString("\n### Rittersaal\n\n", $body);
        self::assertStringContainsString("\n- Bankett (sitzend): 50 - 180 Personen\n", $body);
        self::assertStringContainsString("\n- Seestrasse 1, ", $body);
        self::assertStringContainsString("\n- 0.9 km bis Bahnhof Zürich Stadelhofen\n", $body);
        self::assertStringContainsString("\n- **Parking?** 100 Parkplätze (kostenlos)\n", $body);
        self::assertStringContainsString("\n- [Website](https://seeblick.example)\n", $body);
        self::assertStringContainsString("\n- [Instagram](https://www.instagram.com/schloss.seeblick)\n", $body);
        self::assertStringContainsString("\n- [Anfrage über das Formular](".self::HTML_URL."#anfrage)\n", $body);
        // #53: no own rating or reviews, and no Google value in cached text either.
        self::assertStringNotContainsString('Bewertung', $body);
        self::assertStringNotContainsString('Google', $body);
    }

    public function test_the_phone_number_and_the_email_address_never_appear(): void
    {
        DetailVenues::full($this->em);

        $this->client->request('GET', self::MD);

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(DetailVenues::FULL_PHONE, $body);
        self::assertStringNotContainsString('44 123 45 67', $body);
        self::assertStringNotContainsString('info@seeblick.example', $body);
        self::assertStringNotContainsString('mailto:', $body);
        self::assertStringNotContainsString('/telefon', $body);
    }

    public function test_every_link_is_absolute_on_the_request_host(): void
    {
        DetailVenues::full($this->em);
        $this->client->setServerParameter('HTTPS', 'on');

        $this->client->request('GET', self::MD);

        preg_match_all('~\]\(([^)]*)\)~', (string) $this->client->getResponse()->getContent(), $links);
        $own = array_filter($links[1], static fn (string $link): bool => !str_contains($link, 'seeblick.example') && !str_contains($link, 'instagram.com'));
        self::assertCount(3, $own, 'the inquiry and the two documents');
        foreach ($own as $link) {
            self::assertStringStartsWith('https://localhost/', $link);
        }
    }

    public function test_venue_text_cannot_become_markdown_syntax(): void
    {
        $venue = DetailVenues::minimal('markdown-probe', '*Fett* [Link](https://evil.example)');
        $this->em->flush();
        $this->connection->executeStatement(
            'UPDATE venue_translations SET description = :text WHERE venue_id = :id',
            ['text' => "# Kein Titel\n- keine Liste\n<b>kein HTML</b>", 'id' => $venue->id()],
        );

        $this->client->request('GET', '/eventlokal/markdown-probe.md');

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("# \\*Fett\\* \\[Link\\](https://evil.example)\n", $body);
        self::assertStringContainsString("\n\\# Kein Titel\n\\- keine Liste\n\\<b\\>kein HTML\\</b\\>\n", $body);
    }

    public function test_a_liechtenstein_town_is_followed_by_liechtenstein_without_kanton(): void
    {
        VenueTranslationFactory::createOne(['venue' => \App\Factory\VenueFactory::createOne(['town' => DetailVenues::town('Vaduz')]), 'slug' => 'remise-vaduz']);

        $this->client->request('GET', '/eventlokal/remise-vaduz.md');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString("\n9490 Vaduz, Liechtenstein\n", (string) $this->client->getResponse()->getContent());
    }

    public function test_a_history_slug_redirects_to_the_current_markdown(): void
    {
        $venue = DetailVenues::full($this->em);
        VenueSlugHistoryFactory::createOne(['venue' => $venue, 'slug' => 'altes-schloss']);

        $this->client->request('GET', '/eventlokal/altes-schloss.md');

        self::assertResponseStatusCodeSame(301);
        self::assertSame(self::MD, $this->client->getResponse()->headers->get('Location'));
    }

    public function test_an_unpublished_venue_is_gone_and_an_unknown_one_missing(): void
    {
        VenueTranslationFactory::createOne([
            'venue' => \App\Factory\VenueFactory::createOne(['status' => VenueStatus::Unpublished, 'town' => DetailVenues::town('Bern')]),
            'slug' => 'entwurf',
        ]);

        $this->client->request('GET', '/eventlokal/entwurf.md');
        self::assertResponseStatusCodeSame(410);

        $this->client->request('GET', '/eventlokal/unbekannt.md');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_detail_page_links_its_markdown(): void
    {
        DetailVenues::full($this->em);

        $crawler = $this->client->request('GET', '/eventlokal/'.DetailVenues::FULL_SLUG);

        self::assertResponseIsSuccessful();
        self::assertSame(self::HTML_URL.'.md', $crawler->filter('link[rel="alternate"][type="text/markdown"]')->attr('href'));
    }
}
