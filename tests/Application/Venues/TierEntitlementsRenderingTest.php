<?php

declare(strict_types=1);

namespace App\Tests\Application\Venues;

use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Integration\Venues\DispatchesVenueCommands;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Command\ChangeVenueTier;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * What a plan changes in every output (F12): the detail page's rendered HTML source, its JSON-LD, the Markdown view, the
 * phone reveal, the listing and Merkliste cards, and the sitemap. One venue holds more than Free shows (eight gallery
 * photos, two rooms, two PDFs, a phone, a website, an inquiry address, Instagram); it is built twice, on Free and on
 * Pro, and each output is read as a visitor, a crawler or an AI agent gets it. Counts come from what is in the HTML
 * (distinct photo ids, links), never from templates.
 */
final class TierEntitlementsRenderingTest extends WebTestCase
{
    use DispatchesVenueCommands;
    use Factories;
    use ImportsTownDataset;

    private const string FREE_SLUG = 'haus-klein';
    private const string FREE_NAME = 'Haus Klein';
    private const string PRO_SLUG = 'haus-gross';
    private const string PRO_NAME = 'Haus Gross';
    private const string WEBSITE = 'https://ueberfluss.example';
    private const string INSTAGRAM = 'https://www.instagram.com/haus.ueberfluss';
    private const string SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    private const string IMAGE_NS = 'http://www.google.com/schemas/sitemap-image/1.1';

    /** `photos_max` of the seeded Free row, and the gallery photos the fixture holds. */
    private const int FREE_PHOTOS = 6;
    private const int GALLERY = DetailVenues::OVERFULL_GALLERY;

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $em;
    private int $ips = 0;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $pool = self::getContainer()->get('cache.phone_reveal');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_a_free_page_holds_six_photos_one_room_one_pdf_and_no_phone_or_website(): void
    {
        $free = DetailVenues::overfull(KnownTiers::FREE, self::FREE_SLUG, self::FREE_NAME);

        $crawler = $this->client->request('GET', '/hochzeitslocation/'.self::FREE_SLUG);

        self::assertResponseIsSuccessful();
        $html = $this->html();
        self::assertSame(\array_slice($this->galleryIds($free), 0, self::FREE_PHOTOS), $this->photoIds($html, $free), 'the first six photos by position, and no room photo');
        self::assertCount(self::FREE_PHOTOS, $crawler->filter('#bilder .carousel__slide'));

        self::assertSame([DetailVenues::OVERFULL_ROOMS[0]], $this->roomNames($crawler));
        self::assertStringNotContainsString(DetailVenues::OVERFULL_ROOMS[1], $html);

        self::assertCount(1, $this->pdfLinks($html, $free));
        self::assertStringContainsString(DetailVenues::OVERFULL_DOCUMENTS[0], $html);
        self::assertStringNotContainsString(DetailVenues::OVERFULL_DOCUMENTS[1], $html);

        $source = $this->unescaped($html);
        self::assertStringNotContainsString('/telefon', $source, 'no reveal link');
        self::assertSelectorNotExists('[data-phone-reveal]');
        self::assertStringNotContainsString(self::WEBSITE, $source, 'no website, not even in the JSON-LD');
        self::assertStringNotContainsString(substr(DetailVenues::FULL_PHONE, 3), $source);
        self::assertSelectorNotExists('h1 .badge');
        self::assertStringNotContainsString('Top-Eintrag', $html);

        // The inquiry form, the address and Instagram stay public: the plan hides the phone and the website only.
        self::assertSelectorExists('#kontakt a[href="mailto:anfrage@ueberfluss.example"]');
        self::assertSelectorExists('#kontakt a[href="'.self::INSTAGRAM.'"]');
        self::assertSelectorExists('section#anfrage form');
        self::assertSelectorTextContains('#standort', 'Aarestrasse 4');
    }

    public function test_the_json_ld_and_the_share_image_of_a_free_page(): void
    {
        $free = DetailVenues::overfull(KnownTiers::FREE, self::FREE_SLUG, self::FREE_NAME);

        $crawler = $this->client->request('GET', '/hochzeitslocation/'.self::FREE_SLUG);

        $venue = $this->venueNode($crawler);
        self::assertSame([self::INSTAGRAM], $venue['sameAs'], 'the website is not a sameAs of a Free venue');
        self::assertArrayNotHasKey('telephone', $venue);
        self::assertArrayNotHasKey('email', $venue);
        self::assertIsArray($venue['image']);
        self::assertCount(3, $venue['image']);
        $og = (string) $crawler->filter('meta[property="og:image"]')->attr('content');
        self::assertMatchesRegularExpression('#^http://localhost/media/'.$free->number().'/[0-9a-f-]{36}-1280\.jpg$#', $og, 'the share image stays');
        self::assertContains($og, $venue['image']);
    }

    public function test_the_markdown_view_of_a_free_venue_has_no_website_no_second_room_and_no_second_pdf(): void
    {
        DetailVenues::overfull(KnownTiers::FREE, self::FREE_SLUG, self::FREE_NAME);

        $this->client->request('GET', '/hochzeitslocation/'.self::FREE_SLUG.'.md');

        self::assertResponseIsSuccessful();
        $body = $this->html();
        self::assertStringContainsString('### '.DetailVenues::OVERFULL_ROOMS[0]."\n", $body);
        self::assertStringNotContainsString(DetailVenues::OVERFULL_ROOMS[1], $body);
        self::assertStringContainsString('['.DetailVenues::OVERFULL_DOCUMENTS[0].'](', $body);
        self::assertStringNotContainsString(DetailVenues::OVERFULL_DOCUMENTS[1], $body);
        self::assertStringNotContainsString('[Website]', $body);
        self::assertStringNotContainsString(self::WEBSITE, $body);
        self::assertStringContainsString('[Instagram]('.self::INSTAGRAM.')', $body);
        self::assertStringNotContainsString('/telefon', $body);
        self::assertStringNotContainsString(substr(DetailVenues::FULL_PHONE, 3), $body);
        self::assertStringNotContainsString('Top-Eintrag', $body);
    }

    public function test_the_phone_reveal_answers_404_for_a_free_venue_and_200_for_a_pro_venue(): void
    {
        DetailVenues::overfull(KnownTiers::FREE, self::FREE_SLUG, self::FREE_NAME);
        DetailVenues::overfull(KnownTiers::PRO, self::PRO_SLUG, self::PRO_NAME);

        foreach ([[], ['HTTP_ACCEPT' => 'application/json']] as $server) {
            $this->client->request('GET', '/hochzeitslocation/'.self::FREE_SLUG.'/telefon', server: $this->server($server));
            self::assertResponseStatusCodeSame(404);
        }

        $this->client->request('GET', '/hochzeitslocation/'.self::PRO_SLUG.'/telefon', server: $this->server(['HTTP_ACCEPT' => 'application/json']));
        self::assertResponseIsSuccessful();
        self::assertSame(['phone' => DetailVenues::FULL_PHONE, 'display' => '044 123 45 67'], json_decode($this->html(), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function test_a_pro_page_shows_everything_the_venue_holds(): void
    {
        $pro = DetailVenues::overfull(KnownTiers::PRO, self::PRO_SLUG, self::PRO_NAME);

        $crawler = $this->client->request('GET', '/hochzeitslocation/'.self::PRO_SLUG);

        self::assertResponseIsSuccessful();
        $html = $this->html();
        $ids = $this->photoIds($html, $pro);
        self::assertCount(self::GALLERY + 1, $ids, 'the eight gallery photos and the photo of the first room');
        self::assertSame($this->galleryIds($pro), \array_slice($ids, 0, self::GALLERY));
        self::assertCount(self::GALLERY, $crawler->filter('#bilder .carousel__slide'));

        self::assertSame(DetailVenues::OVERFULL_ROOMS, $this->roomNames($crawler));
        self::assertCount(2, $this->pdfLinks($html, $pro));
        foreach (DetailVenues::OVERFULL_DOCUMENTS as $document) {
            self::assertStringContainsString($document, $html);
        }

        self::assertSelectorExists('#kontakt a[data-phone-reveal][href="/hochzeitslocation/'.self::PRO_SLUG.'/telefon"]');
        self::assertSelectorExists('#kontakt a[href="'.self::WEBSITE.'"][rel="nofollow noopener"]');
        self::assertStringNotContainsString(substr(DetailVenues::FULL_PHONE, 3), $html, 'the number itself is never in the page');
        self::assertSelectorTextContains('h1 .badge', 'Top-Eintrag');

        $venue = $this->venueNode($crawler);
        self::assertSame([self::WEBSITE, self::INSTAGRAM], $venue['sameAs']);
        self::assertArrayNotHasKey('telephone', $venue, 'a phone number is never in JSON-LD, whatever the plan');
    }

    public function test_the_markdown_view_of_a_pro_venue_has_the_website_and_both_rooms_and_pdfs(): void
    {
        DetailVenues::overfull(KnownTiers::PRO, self::PRO_SLUG, self::PRO_NAME);

        $this->client->request('GET', '/hochzeitslocation/'.self::PRO_SLUG.'.md');

        self::assertResponseIsSuccessful();
        $body = $this->html();
        self::assertStringContainsString('[Website]('.self::WEBSITE.')', $body);
        foreach (DetailVenues::OVERFULL_ROOMS as $room) {
            self::assertStringContainsString('### '.$room."\n", $body);
        }
        foreach (DetailVenues::OVERFULL_DOCUMENTS as $document) {
            self::assertStringContainsString('['.$document.'](', $body);
        }
        self::assertStringNotContainsString('/telefon', $body, 'not even a Pro venue puts a phone route into the Markdown');
        self::assertStringNotContainsString(substr(DetailVenues::FULL_PHONE, 3), $body);
    }

    public function test_the_listing_and_the_merkliste_carry_the_label_for_pro_only_and_cut_a_free_card_to_six_photos(): void
    {
        $free = DetailVenues::overfull(KnownTiers::FREE, self::FREE_SLUG, self::FREE_NAME);
        $pro = DetailVenues::overfull(KnownTiers::PRO, self::PRO_SLUG, self::PRO_NAME);

        $listing = $this->client->request('GET', '/hochzeitslocations');
        self::assertResponseIsSuccessful();
        $this->assertCards($listing);
        self::assertSame([self::PRO_NAME, self::FREE_NAME], $listing->filter('.card__link')->each(static fn (Crawler $link): string => $link->text()), 'Pro first');

        $this->client->getCookieJar()->set(new Cookie('merkliste', $free->number().','.$pro->number(), null, '/', 'localhost'));
        $merkliste = $this->client->request('GET', '/merkliste');
        self::assertResponseIsSuccessful();
        $this->assertCards($merkliste);
        self::assertSame([self::FREE_NAME, self::PRO_NAME], $merkliste->filter('.card__link')->each(static fn (Crawler $link): string => $link->text()), 'in the order saved');
    }

    public function test_the_sitemap_lists_six_images_for_a_free_venue_and_all_nine_for_a_pro_venue(): void
    {
        $free = DetailVenues::overfull(KnownTiers::FREE, self::FREE_SLUG, self::FREE_NAME);
        DetailVenues::overfull(KnownTiers::PRO, self::PRO_SLUG, self::PRO_NAME);

        $freeImages = $this->sitemapImages(self::FREE_SLUG);
        $proImages = $this->sitemapImages(self::PRO_SLUG);

        self::assertCount(self::FREE_PHOTOS, $freeImages);
        self::assertCount(self::GALLERY + 1, $proImages);
        self::assertSame(
            \array_slice($this->galleryIds($free), 0, self::FREE_PHOTOS),
            array_map(static fn (string $image): string => substr($image, \strlen('http://localhost/media/'.$free->number().'/'), 36), $freeImages),
        );
    }

    /**
     * The page is cached for a day (S10) and a tier change purges it: the next visitor reads the new plan's content.
     */
    public function test_a_tier_change_purges_the_cache_and_the_next_render_shows_the_new_plan(): void
    {
        $venue = DetailVenues::overfull(KnownTiers::FREE, self::FREE_SLUG, self::FREE_NAME);
        $before = $this->client->request('GET', '/hochzeitslocation/'.self::FREE_SLUG);
        self::assertResponseIsSuccessful();
        self::assertCount(self::FREE_PHOTOS, $this->photoIds($this->html(), $venue));
        self::assertStringNotContainsString('/telefon', $this->html());
        self::assertSelectorNotExists('h1 .badge');
        self::assertSame([DetailVenues::OVERFULL_ROOMS[0]], $this->roomNames($before));
        $purger = $this->purger();
        self::assertSame([[], 0], [$purger->venues, $purger->listings], 'rendering purges nothing');

        $this->dispatch(new ChangeVenueTier($venue->number(), KnownTiers::PRO));

        self::assertSame([$venue->number()], $purger->venues);
        self::assertSame(1, $purger->listings);
        $after = $this->client->request('GET', '/hochzeitslocation/'.self::FREE_SLUG);
        self::assertResponseIsSuccessful();
        self::assertCount(self::GALLERY + 1, $this->photoIds($this->html(), $venue));
        self::assertSelectorExists('#kontakt a[data-phone-reveal][href="/hochzeitslocation/'.self::FREE_SLUG.'/telefon"]');
        self::assertSelectorTextContains('h1 .badge', 'Top-Eintrag');
        self::assertSame(DetailVenues::OVERFULL_ROOMS, $this->roomNames($after));
        self::assertSame([self::WEBSITE, self::INSTAGRAM], $this->venueNode($after)['sameAs']);
    }

    /**
     * And back: a venue that falls back to Free keeps its rows, and the page shows the Free cut again.
     */
    public function test_a_venue_that_falls_back_to_free_is_cut_again_without_losing_a_row(): void
    {
        $venue = DetailVenues::overfull(KnownTiers::PRO, self::PRO_SLUG, self::PRO_NAME);
        $this->client->request('GET', '/hochzeitslocation/'.self::PRO_SLUG);
        self::assertCount(self::GALLERY + 1, $this->photoIds($this->html(), $venue));

        $this->dispatch(new ChangeVenueTier($venue->number(), KnownTiers::FREE));

        $crawler = $this->client->request('GET', '/hochzeitslocation/'.self::PRO_SLUG);
        self::assertCount(self::FREE_PHOTOS, $this->photoIds($this->html(), $venue));
        self::assertSame([DetailVenues::OVERFULL_ROOMS[0]], $this->roomNames($crawler));
        self::assertStringNotContainsString('/telefon', $this->html());
        self::assertSame(self::GALLERY + 1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_photos WHERE venue_id = ?', [$venue->id()]), 'hidden, not deleted');
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM rooms WHERE venue_id = ?', [$venue->id()]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_documents WHERE venue_id = ?', [$venue->id()]));
    }

    private function assertCards(Crawler $page): void
    {
        $free = $this->card($page, self::FREE_NAME);
        $pro = $this->card($page, self::PRO_NAME);

        self::assertCount(0, $free->filter('.badge'), 'no label on a Free card');
        self::assertSame('Top-Eintrag', $pro->filter('.card__title .badge')->text());
        self::assertCount(self::FREE_PHOTOS, $free->filter('.carousel__slide'), 'a Free card shows at most six photos');
        self::assertCount(8, $pro->filter('.carousel__slide'), 'a card shows eight at most');
    }

    /**
     * @return list<string> the names on the cards of the Räume carousel
     */
    private function roomNames(Crawler $page): array
    {
        return $page->filter('.venue-room__name')->each(static fn (Crawler $name): string => $name->text());
    }

    private function card(Crawler $page, string $name): Crawler
    {
        $card = $page->filter('.card')->reduce(static fn (Crawler $card): bool => $card->filter('.card__link')->text() === $name);
        self::assertCount(1, $card, $name);

        return $card;
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * JSON-LD escapes slashes; a visitor of the source reads them plain.
     */
    private function unescaped(string $html): string
    {
        return str_replace('\/', '/', $html);
    }

    /**
     * @return list<string> the distinct ids of the venue's photos in the HTML, in order of first appearance
     */
    private function photoIds(string $html, Venue $venue): array
    {
        preg_match_all('#/media/'.$venue->number().'/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})-\d+\.(?:avif|webp|jpg)#', $html, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return list<string>
     */
    private function pdfLinks(string $html, Venue $venue): array
    {
        preg_match_all('#href="/media/'.$venue->number().'/[0-9a-f-]{36}\.pdf"#', $html, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * @return list<string> the ids of the venue's gallery photos, by position
     */
    private function galleryIds(Venue $venue): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn('SELECT id FROM venue_photos WHERE venue_id = ? AND room_id IS NULL ORDER BY position', [$venue->id()]);

        return $ids;
    }

    /**
     * @return list<string> the `image:loc` values of the venue's entry in the sitemap
     */
    private function sitemapImages(string $slug): array
    {
        $this->client->request('GET', '/sitemap-venues-1.xml');
        self::assertResponseIsSuccessful();
        $xml = new \DOMDocument();
        self::assertTrue($xml->loadXML($this->html()));

        foreach ($xml->getElementsByTagNameNS(self::SITEMAP_NS, 'url') as $url) {
            if (!str_ends_with((string) $url->getElementsByTagNameNS(self::SITEMAP_NS, 'loc')->item(0)?->textContent, '/'.$slug)) {
                continue;
            }
            $images = [];
            foreach ($url->getElementsByTagNameNS(self::IMAGE_NS, 'loc') as $image) {
                $images[] = $image->textContent;
            }

            return $images;
        }

        self::fail('The sitemap has no entry for '.$slug);
    }

    /**
     * @return array<string, mixed>
     */
    private function venueNode(Crawler $crawler): array
    {
        $nodes = array_values(array_filter(
            $crawler->filter('script[type="application/ld+json"]')->each(static function (Crawler $script): array {
                $data = json_decode($script->text(null, false), true, flags: \JSON_THROW_ON_ERROR);
                self::assertIsArray($data);

                /* @var array<string, mixed> */
                return $data;
            }),
            static fn (array $block): bool => str_ends_with((string) ($block['@id'] ?? ''), '#venue'),
        ));
        self::assertCount(1, $nodes);

        return $nodes[0];
    }

    /**
     * A client of its own for every reveal, so the limiter of one test step never decides another.
     *
     * @param array<string, string> $server
     *
     * @return array<string, string>
     */
    private function server(array $server): array
    {
        return ['REMOTE_ADDR' => '10.20.30.'.(++$this->ips)] + $server;
    }
}
