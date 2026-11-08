<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * One capacity rule (F5b, `VenueCapacity`): a venue whose own figure is stale, as DemoStory's every
 * fifth venue from the third on (a third of its first room's maximum, no minimum), shows its first
 * room's maximum everywhere it is shown or published: the card, the detail head, the JSON-LD, the
 * markdown view, the similar-venues band and `/llms.txt`.
 */
final class OneCapacityTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const string SLUG = 'scheune-mit-saal';
    private const string NAME = 'Scheune Mit Saal';

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

        $stale = $this->venue(self::SLUG, self::NAME, 300);
        RoomFactory::createOne(['venue' => $stale, 'position' => 1, 'capacityMin' => 100, 'capacityMax' => 900]);
        $this->venue('nachbar-ohne-raum', 'Nachbar Ohne Raum', 120);
        $withMinimum = $this->venue('halle-mit-minimum', 'Halle Mit Minimum', 300, 50);
        RoomFactory::createOne(['venue' => $withMinimum, 'position' => 1, 'capacityMin' => 20, 'capacityMax' => 900]);
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_the_card_the_detail_page_and_its_json_ld_show_the_first_rooms_maximum(): void
    {
        $listing = $this->get('/eventlokale?guests=850');
        $card = $listing->filter('.card')->reduce(static fn (Crawler $card): bool => self::NAME === $card->filter('.card__link')->text());
        self::assertCount(1, $card, 'The room lets the venue through the guest filter.');
        self::assertSame('900', $card->filter('.card-facts__item')->first()->text());

        $detail = $this->get('/eventlokal/'.self::SLUG);
        self::assertStringContainsString('900 Personen', $detail->filter('.venue-head__facts')->text());
        self::assertStringNotContainsString('300', $detail->filter('.venue-head__facts')->text());
        self::assertSame([900], $this->attendeeCapacities($detail));
    }

    public function test_the_least_minimum_shows_on_the_card_and_the_detail_head(): void
    {
        $listing = $this->get('/eventlokale?guests=850');
        $card = $listing->filter('.card')->reduce(static fn (Crawler $card): bool => 'Halle Mit Minimum' === $card->filter('.card__link')->text());
        self::assertCount(1, $card);
        self::assertSame('20 - 900', $card->filter('.card-facts__item')->first()->text());

        self::assertStringContainsString('20 - 900 Personen', $this->get('/eventlokal/halle-mit-minimum')->filter('.venue-head__facts')->text());
    }

    public function test_the_markdown_view_the_similar_band_and_llms_txt_show_the_same_maximum(): void
    {
        $this->client->request('GET', '/eventlokal/'.self::SLUG.'.md');
        self::assertResponseIsSuccessful();
        $markdown = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString("\n- 900 Personen\n", $markdown, 'The head line, not the room line "100 - 900".');
        self::assertStringNotContainsString('300 Personen', $markdown);

        $band = $this->get('/eventlokal/nachbar-ohne-raum')->filter('.similar-card')
            ->reduce(static fn (Crawler $card): bool => str_contains($card->text(), self::NAME));
        self::assertCount(1, $band);
        self::assertStringContainsString('900', $band->text());
        self::assertStringNotContainsString('300', $band->text());

        $this->client->request('GET', '/llms.txt');
        self::assertResponseIsSuccessful();
        $line = array_values(array_filter(
            explode("\n", (string) $this->client->getResponse()->getContent()),
            static fn (string $line): bool => str_starts_with($line, '- ['.self::NAME.']'),
        ));
        self::assertCount(1, $line);
        self::assertStringContainsString('bis 900 Personen', $line[0]);
    }

    private function get(string $uri): Crawler
    {
        $crawler = $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * Every `maximumAttendeeCapacity` in the page's JSON-LD, at any depth.
     *
     * @return list<mixed>
     */
    private function attendeeCapacities(Crawler $page): array
    {
        $found = [];
        $walk = static function (mixed $node) use (&$walk, &$found): void {
            if (!\is_array($node)) {
                return;
            }
            if (\array_key_exists('maximumAttendeeCapacity', $node)) {
                $found[] = $node['maximumAttendeeCapacity'];
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        foreach ($page->filter('script[type="application/ld+json"]') as $script) {
            $walk(json_decode((string) $script->textContent, true, flags: \JSON_THROW_ON_ERROR));
        }

        return $found;
    }

    private function venue(string $slug, string $name, int $max, ?int $min = null): Venue
    {
        $venue = VenueFactory::createOne([
            'name' => $name,
            'status' => VenueStatus::Published,
            'town' => DetailVenues::town('Zürich'),
            'capacityMin' => $min,
            'capacityMax' => $max,
        ]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => $slug]);

        return $venue;
    }
}
