<?php

declare(strict_types=1);

namespace App\Tests\Application\Venues;

use App\Factory\VenueFactory;
use App\Factory\VenueSlugHistoryFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\ValueObject\PhoneNumber;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\TermGroup;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The venue detail page (#8). The client shares the test's connection (no reboot), so the
 * venues created here are visible to the request and rolled back afterwards.
 */
final class VenueDetailControllerTest extends WebTestCase
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
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_renders_every_section_of_a_full_venue(): void
    {
        $venue = DetailVenues::full($this->em);
        for ($i = 1; $i <= 13; ++$i) {
            DetailVenues::minimal('andere-location-'.$i, 'Andere Location '.$i);
        }

        $crawler = $this->page('/eventlokal/'.DetailVenues::FULL_SLUG);

        self::assertSame('Schloss Seeblick in Zürich mieten | deine-eventlocation.ch', $crawler->filter('title')->text());
        self::assertSame('Schloss Seeblick: Hochzeit am See.', $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertCount(1, $crawler->filter('h1'));
        self::assertStringContainsString('Schloss Seeblick', $crawler->filter('h1')->text());
        self::assertSelectorTextContains('h1 .badge', 'Top-Eintrag');

        // Nav: every item points at an existing section.
        $targets = $crawler->filter('.venue-nav a')->each(static fn (Crawler $a): string => (string) $a->attr('href'));
        self::assertSame(['#beschreibung', '#bilder', '#kontakt', '#standort'], $targets);
        foreach ($targets as $target) {
            self::assertCount(1, $crawler->filter($target), $target);
        }

        // Hero and gallery.
        self::assertCount(1, $crawler->filter('[fetchpriority="high"]'));
        self::assertCount(1, $crawler->filter('link[rel="preload"][as="font"]'));
        self::assertCount(3, $crawler->filter('#bilder .carousel__slide'));
        self::assertCount(0, $crawler->filter('.venue-placeholder'));
        // Slides 2+ yield to the LCP slide (#41 F-M1, baseline §8 Fix 1's lever).
        self::assertCount(1, $crawler->filter('#bilder .carousel__slide:first-child img[fetchpriority="high"]'));
        self::assertCount(2, $crawler->filter('#bilder .carousel__slide img[loading="lazy"][fetchpriority="low"]'));
        self::assertSelectorExists('button[commandfor="venue-gallery"][command="show-modal"]');
        self::assertSelectorExists('dialog#venue-gallery[data-dialog]');
        self::assertCount(3, $crawler->filter('#venue-gallery .carousel__slide'));

        // Actions card.
        self::assertSelectorExists('aside.venue-actions a[href="#anfrage"]');
        self::assertSelectorExists('aside.venue-actions [data-print][hidden]');
        self::assertSelectorExists('aside.venue-actions [popovertarget="venue-share"][data-share-url="http://localhost/eventlokal/schloss-seeblick"]');
        self::assertSelectorExists('#venue-share[popover] a[href^="https://wa.me/?text="]');
        // Merken: #7's no-JS form (#13 adds the route and the behaviour).
        self::assertSelectorExists('aside.venue-actions form.merken--detail[method="post"][action="/merkliste/'.$venue->number().'"] button.merken__button');

        // Head.
        self::assertSelectorTextContains('.venue-head__place', '8001 Zürich');
        $facts = $crawler->filter('.venue-head__facts li')->each(static fn (Crawler $li): string => $li->text());
        self::assertSame(['Schloss', 'Am Seeufer', '50 - 300 Personen'], $facts);
        self::assertCount(2, $crawler->filter('.venue-head__description p'));

        // Fact lists: groups in DP-7 order, two lists and no rule between them (no board draws one).
        self::assertSame(
            ['Art der Location', 'Lage', 'Geeignet für', 'Räume und Infrastruktur', 'Rund um die Location'],
            $crawler->filter('.venue-facts dt')->each(static fn (Crawler $dt): string => $dt->text()),
        );
        self::assertCount(2, $crawler->filter('.venue-facts dl'));
        self::assertCount(0, $crawler->filter('.venue-facts hr'));

        // Räume with both dialogs on the first room and none on the second.
        self::assertSame(['Rittersaal', 'Gewölbekeller'], $crawler->filter('.venue-room__name')->each(static fn (Crawler $h): string => $h->text()));
        self::assertSelectorExists('button[commandfor="room-1-info"]');
        self::assertSelectorExists('button[commandfor="room-1-facts"]');
        self::assertSelectorNotExists('button[commandfor^="room-2-"]');
        self::assertSelectorTextContains('#room-1-info', 'Konzert/Reihen (sitzend): 60 - 250 Personen');
        self::assertSelectorTextContains('#room-1-info', 'Seminar/Parlament (sitzend): 120 Personen');
        self::assertSelectorTextContains('#room-1-info', 'U-Form (sitzend): 40 Personen');
        self::assertSelectorTextContains('#room-1-info', 'Block (sitzend): 60 - 80 Personen');
        self::assertSelectorTextContains('#room-1-info', 'Der grosse Saal mit Kronleuchtern.');
        self::assertSelectorTextContains('#room-1-facts', 'Fläche: 220 m²');
        self::assertSelectorTextContains('#room-1-facts', 'Raumhöhe: 4 m');
        self::assertSelectorTextContains('#room-1-facts', 'Boden: Parkett');

        // Häufige Fragen.
        self::assertSame(
            ['Eigenes Catering?', 'Tageslicht?', 'Parking?'],
            $crawler->filter('.venue-faq dt')->each(static fn (Crawler $dt): string => $dt->text()),
        );
        self::assertSelectorTextContains('.venue-faq', 'Eigenes Catering möglich');
        self::assertSelectorTextContains('.venue-faq', '100 Parkplätze (kostenlos)');

        // Standort.
        self::assertSelectorTextContains('#standort', 'Seestrasse 1, 8001 Zürich');
        self::assertSame(
            ['0.9 km bis Bahnhof Zürich Stadelhofen', '3.2 km bis Autobahn', '12.0 km bis Flughafen Zürich'],
            $crawler->filter('.venue-location__distances li')->each(static fn (Crawler $li): string => $li->text()),
        );

        // Kontakt: the reveal link, never the number.
        self::assertSelectorExists('#kontakt a[data-phone-reveal][rel="nofollow"][href="/eventlokal/schloss-seeblick/telefon"]');
        self::assertSelectorExists('#kontakt a[href="mailto:info@seeblick.example"][data-address="info@seeblick.example"]');
        self::assertSelectorExists('#kontakt a[href="https://seeblick.example"][rel="nofollow noopener"]');
        self::assertSelectorTextContains('#kontakt p.venue-contact__person', 'Anna Muster');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(DetailVenues::FULL_PHONE, $html);
        self::assertStringNotContainsString(PhoneNumber::fromE164(DetailVenues::FULL_PHONE)->display(), $html);
        self::assertStringNotContainsString('441234567', $html);

        // Dokumente.
        self::assertCount(2, $crawler->filter('.venue-documents a[href^="/media/'.$venue->number().'/"][href$=".pdf"]'));
        self::assertSelectorTextContains('.venue-documents', 'Preisliste');

        // #14's form fills the slot.
        self::assertCount(1, $crawler->filter('section#anfrage form'));

        // No own rating or reviews since #53.
        self::assertCount(0, $crawler->filter('#bewertung, .venue-rating, .venue-reviews'));

        // Ähnliche Locations: 12 cards, linking to the detail pages, and "Alle Locations".
        self::assertSelectorTextContains('.similar-venues h2', 'Ähnliche Locations');
        self::assertCount(12, $crawler->filter('.similar-venues .similar-card'));
        self::assertSelectorExists('.similar-venues .similar-card a[href^="/eventlokal/andere-location-"]');
        self::assertSelectorTextContains('.similar-venues__all', 'Alle Locations');
        self::assertSame('/eventlokale', $crawler->filter('.similar-venues__all')->attr('href'));
        self::assertCount(12, $crawler->filter('.similar-venues .similar-card .card-facts'));

        // No empty headings, no inline styles or scripts.
        $this->assertNoEmptyHeadings($crawler);
        self::assertCount(0, $crawler->filter('[style]'));
        self::assertCount(0, $crawler->filter('style'));
        self::assertCount(0, $crawler->filter('script:not([src]):not([type="application/ld+json"])'));
    }

    public function test_headers_are_publicly_cacheable_without_session(): void
    {
        DetailVenues::minimal();

        $this->page('/eventlokal/'.DetailVenues::MINIMAL_SLUG);
        $this->assertPublicCache();
    }

    public function test_a_minimal_venue_hides_its_empty_sections(): void
    {
        DetailVenues::minimal();

        $crawler = $this->page('/eventlokal/'.DetailVenues::MINIMAL_SLUG);

        self::assertSame(['#beschreibung', '#standort'], $crawler->filter('.venue-nav a')->each(static fn (Crawler $a): string => (string) $a->attr('href')));
        self::assertSelectorNotExists('#bilder');
        self::assertSelectorNotExists('.venue-hero');
        // F6: where the hero would stand, the brand's placeholder: no carousel, no link, no text.
        self::assertSelectorExists('.venue-placeholder > .photo-placeholder[aria-hidden="true"]');
        self::assertSelectorNotExists('.venue-placeholder a, .venue-placeholder button, .venue-placeholder img');
        self::assertSelectorNotExists('button[commandfor="venue-gallery"]');
        self::assertSelectorNotExists('#venue-gallery');
        self::assertSelectorNotExists('[fetchpriority="high"]');
        self::assertSelectorNotExists('h1 .badge');
        self::assertSelectorNotExists('#bewertung');
        self::assertSelectorNotExists('.venue-rating');
        self::assertSelectorNotExists('.venue-facts');
        self::assertSelectorNotExists('.venue-rooms');
        self::assertSelectorNotExists('.venue-faq');
        self::assertSelectorNotExists('#kontakt');
        self::assertSelectorNotExists('.venue-documents');
        self::assertSelectorNotExists('.venue-reviews');
        self::assertSelectorNotExists('.similar-venues');
        self::assertSelectorNotExists('.venue-location__distances');
        self::assertSelectorTextContains('#standort', DetailVenues::town('Bern')->zip().' Bern');
        self::assertSame(['80 Personen'], $crawler->filter('.venue-head__facts li')->each(static fn (Crawler $li): string => $li->text()));
        self::assertSame('Ein kleines Gartenhaus.', $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertSelectorTextContains('.venue-head__description', 'Ein kleines Gartenhaus.');
        $this->assertNoEmptyHeadings($crawler);
    }

    public function test_a_venue_with_no_place_renders_without_a_location(): void
    {
        DetailVenues::withoutPlace();
        DetailVenues::minimal();

        $crawler = $this->page('/eventlokal/wiesenhof-ohne-ort');

        self::assertSame('Wiesenhof Ohne Ort | deine-eventlocation.ch', $crawler->filter('title')->text());
        self::assertCount(0, $crawler->filter('#standort'));
        self::assertCount(0, $crawler->filter('.venue-nav a[href="#standort"]'));
        self::assertCount(0, $crawler->filter('.venue-head__place'));
        self::assertSame('Wiesenhof Ohne Ort', $crawler->filter('[data-share-title]')->attr('data-share-title'));
        self::assertCount(1, $crawler->filter('.similar-venues .similar-card'));
    }

    public function test_a_canton_only_venue_names_its_canton(): void
    {
        DetailVenues::inCantonOnly();

        $crawler = $this->page('/eventlokal/scheune-nur-kanton');

        self::assertSame('Scheune Nur Kanton im Kanton Zürich | deine-eventlocation.ch', $crawler->filter('title')->text());
        self::assertSelectorTextSame('.venue-head__place', 'Kanton Zürich');
        self::assertCount(0, $crawler->filter('#standort'));
        self::assertSame('Scheune Nur Kanton im Kanton Zürich', $crawler->filter('[data-share-title]')->attr('data-share-title'));
    }

    public function test_a_liechtenstein_venue_is_named_without_kanton(): void
    {
        DetailVenues::inCantonOnly('remise-vaduz', 'Remise Vaduz', 'li');

        $crawler = $this->page('/eventlokal/remise-vaduz');

        self::assertSame('Remise Vaduz in Liechtenstein | deine-eventlocation.ch', $crawler->filter('title')->text());
        self::assertSelectorTextSame('.venue-head__place', 'Liechtenstein');
        self::assertSame('Remise Vaduz in Liechtenstein', $crawler->filter('[data-share-title]')->attr('data-share-title'));
        self::assertStringNotContainsString('Kanton Liechtenstein', (string) $this->client->getResponse()->getContent());
    }

    public function test_a_region_only_venue_shows_the_text_verbatim(): void
    {
        DetailVenues::inRegionOnly($this->em, 'Schweizweit');

        $crawler = $this->page('/eventlokal/remise-nur-region');

        self::assertSame('Remise Nur Region | deine-eventlocation.ch', $crawler->filter('title')->text());
        self::assertSelectorTextSame('.venue-head__place', 'Schweizweit');
        self::assertSame('Remise Nur Region', $crawler->filter('[data-share-title]')->attr('data-share-title'));
    }

    public function test_distances_alone_keep_the_location_section_without_an_address(): void
    {
        $venue = DetailVenues::withoutPlace();
        $venue->changeDistances('0.9', 'Bahnhof Beispiel', null, null, null, null);
        $this->em->flush();

        $crawler = $this->page('/eventlokal/wiesenhof-ohne-ort');

        self::assertCount(1, $crawler->filter('#standort'));
        self::assertCount(1, $crawler->filter('.venue-nav a[href="#standort"]'));
        self::assertCount(0, $crawler->filter('.venue-location__address'));
        self::assertSelectorTextContains('.venue-location__distances', 'Bahnhof Beispiel');
    }

    public function test_the_event_section_shows_every_fact(): void
    {
        DetailVenues::full($this->em);

        $crawler = $this->page('/eventlokal/'.DetailVenues::FULL_SLUG);

        self::assertSelectorTextSame('.venue-event h2', 'Angaben zur Location');
        self::assertSame(
            ['Preisniveau', 'Ambiente', 'Sitzplätze und Stehplätze', 'Ausstattung', 'Catering', 'Sprachen', 'Öffnungszeiten'],
            $crawler->filter('.venue-event dt')->each(static fn (Crawler $dt): string => $dt->text()),
        );
        self::assertSame(
            [
                'Mittel',
                'Romantisch am Wasser',
                "1'200 Sitzplätze 1'500 Stehplätze Mit Tanzfläche 900 Sitzplätze.",
                'Tanzfläche',
                'Vegetarisch möglich Glutenfrei möglich',
                'Deutsch Englisch',
                'Mai bis Oktober, täglich ab 10 Uhr',
            ],
            $crawler->filter('.venue-event dd')->each(static fn (Crawler $dd): string => $dd->text()),
        );
        self::assertSelectorExists('.venue-facts + .venue-event');
        $instagram = $crawler->filter('#kontakt a[href="https://www.instagram.com/schloss.seeblick"]');
        self::assertCount(1, $instagram);
        self::assertSame('Instagram', $instagram->text());
        self::assertSame('nofollow noopener', $instagram->attr('rel'));
        self::assertSame('_blank', $instagram->attr('target'));
    }

    public function test_the_event_section_shows_only_known_facts(): void
    {
        $venue = DetailVenues::minimal();
        $venue->changePriceLevel(PriceLevel::Exclusive);
        $venue->changeSeatingCapacity(null, 80);
        $this->em->flush();

        $crawler = $this->page('/eventlokal/'.DetailVenues::MINIMAL_SLUG);

        self::assertSame(['Preisniveau', 'Sitzplätze und Stehplätze'], $crawler->filter('.venue-event dt')->each(static fn (Crawler $dt): string => $dt->text()));
        self::assertSame(['Exklusiv', '80 Stehplätze'], $crawler->filter('.venue-event dd')->each(static fn (Crawler $dd): string => $dd->text()));
        self::assertSelectorNotExists('.venue-event .venue-facts__values');
    }

    public function test_technology_is_a_fact_list_and_catering_an_event_fact(): void
    {
        $venue = DetailVenues::minimal();
        VenueTermFactory::new()->term(TermGroup::Technology, 'wifi')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Technology, 'video_conferencing')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Catering, 'coffee_break')->create(['venue' => $venue]);
        $this->em->flush();

        $crawler = $this->page('/eventlokal/'.DetailVenues::MINIMAL_SLUG);

        self::assertSame(['Technik'], $crawler->filter('.venue-facts dt')->each(static fn (Crawler $dt): string => $dt->text()));
        self::assertSame(['WLAN Videokonferenz'], $crawler->filter('.venue-facts dd')->each(static fn (Crawler $dd): string => $dd->text()));
        self::assertSame(['Catering'], $crawler->filter('.venue-event dt')->each(static fn (Crawler $dt): string => $dt->text()));
        self::assertSame(['Kaffeepausen / Pausenverpflegung'], $crawler->filter('.venue-event dd')->each(static fn (Crawler $dd): string => $dd->text()));
    }

    public function test_a_venue_without_facts_has_no_event_section(): void
    {
        DetailVenues::minimal();

        $crawler = $this->page('/eventlokal/'.DetailVenues::MINIMAL_SLUG);

        self::assertCount(0, $crawler->filter('.venue-event'));
        self::assertCount(0, $crawler->filter('a[href*="instagram"]'));
    }

    /**
     * @return iterable<string, array{string, ?string}> stored value, expected link (null: no link)
     */
    public static function storedInstagram(): iterable
    {
        // The old `UrlType` field stored a handle typed with or without `@` like this.
        yield 'handle' => ['https://remiserebhalde', 'https://www.instagram.com/remiserebhalde'];
        yield '@handle' => ['https://@remiserebhalde', 'https://www.instagram.com/remiserebhalde'];
        yield 'profile URL' => ['https://instagram.com/remiserebhalde/?hl=de', 'https://www.instagram.com/remiserebhalde'];
        yield 'garbage: another site' => ['https://www.remiserebhalde.ch', null];
        yield 'garbage: a post' => ['https://www.instagram.com/p/C0abc123/', null];
    }

    /** Existing rows link right at render time, without a data migration; one that names no profile has no link (#45 F-m3). */
    #[DataProvider('storedInstagram')]
    public function test_a_stored_instagram_links_the_profile_or_nothing(string $stored, ?string $expected): void
    {
        $venue = DetailVenues::full($this->em);
        $this->em->flush();
        $this->connection->executeStatement('UPDATE venues SET instagram = ? WHERE id = ?', [$stored, $venue->id()]);

        $crawler = $this->page('/eventlokal/'.DetailVenues::FULL_SLUG);

        $links = $crawler->filter('#kontakt a[data-stats-click="instagram"]')->each(static fn (Crawler $a): string => (string) $a->attr('href'));
        self::assertSame(null === $expected ? [] : [$expected], $links);
        self::assertStringNotContainsString($stored, (string) $this->client->getResponse()->getContent());
    }

    public function test_an_old_slug_redirects_permanently(): void
    {
        $venue = DetailVenues::minimal();
        VenueSlugHistoryFactory::createOne(['venue' => $venue, 'slug' => 'altes-gartenhaus']);

        $this->client->request('GET', '/eventlokal/altes-gartenhaus');

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('/eventlokal/'.DetailVenues::MINIMAL_SLUG, 301);
        $this->assertPublicCache();
    }

    public function test_unknown_and_garbage_slugs_are_not_found(): void
    {
        DetailVenues::minimal();

        $this->client->request('GET', '/eventlokal/gibt-es-nicht');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/eventlokal/Foo%20Bar');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/eventlokal/-kaputt-');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_an_unpublished_venue_is_gone(): void
    {
        $venue = DetailVenues::minimal();
        VenueSlugHistoryFactory::createOne(['venue' => $venue, 'slug' => 'altes-gartenhaus']);
        $venue->unpublish();
        $this->em->flush();

        $this->client->request('GET', '/eventlokal/'.DetailVenues::MINIMAL_SLUG);
        self::assertResponseStatusCodeSame(410);

        $this->client->request('GET', '/eventlokal/altes-gartenhaus');
        self::assertResponseStatusCodeSame(410);
    }

    public function test_a_venue_without_a_german_translation_is_not_reachable(): void
    {
        // No `de` translation row means no German slug to match: 404 (its history slugs give 410).
        $venue = VenueFactory::createOne(['town' => DetailVenues::town('Bern')]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'locale' => 'fr', 'slug' => 'maison-sans-allemand']);

        $this->client->request('GET', '/eventlokal/maison-sans-allemand');
        self::assertResponseStatusCodeSame(404);
    }

    private function page(string $uri): Crawler
    {
        $crawler = $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function assertPublicCache(): void
    {
        $response = $this->client->getResponse();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('s-maxage=86400', $cacheControl);
        self::assertStringContainsString('stale-while-revalidate=86400', $cacheControl);
        self::assertStringContainsString('stale-if-error=86400', $cacheControl);
        self::assertStringNotContainsString('private', $cacheControl);
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertSame([], $response->headers->getCookies());
    }

    private function assertNoEmptyHeadings(Crawler $crawler): void
    {
        foreach ($crawler->filter('h1, h2, h3') as $heading) {
            self::assertNotSame('', trim($heading->textContent), 'Empty heading.');
        }
    }
}
