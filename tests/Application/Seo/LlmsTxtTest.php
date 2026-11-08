<?php

declare(strict_types=1);

namespace App\Tests\Application\Seo;

use App\Tests\Support\Http\PublicRouteFixtures;
use App\Tests\Support\Venues\DetailVenues;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * `/llms.txt` (llmstxt.org): H1, summary quote, H2 sections of `- [name](url): note`, "Optional"
 * last, and every link one that answers 200.
 */
final class LlmsTxtTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use PublicRouteFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        self::mockTime(self::FROZEN_NOW);
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_the_file_follows_the_llms_txt_shape(): void
    {
        $data = $this->seedPublicRoutes();

        $body = $this->llmsTxt();

        self::assertSame('text/plain; charset=UTF-8', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith("# deine-eventlocation.ch\n\n> Hochzeitslocations in der ganzen Schweiz", $body);
        self::assertStringEndsWith("](http://localhost/datenschutz)\n", $body);
        self::assertSame(
            ['## Suche', '## Kantone', '## Orte', '## Location-Arten', '## Locations', '## Optional'],
            array_values(preg_grep('/^## /', explode("\n", $body)) ?: []),
        );
        self::assertStringContainsString("\n- [Startseite](http://localhost/): ", $body);
        self::assertStringContainsString("\n- [Alle Hochzeitslocations](http://localhost/eventlokale): ", $body);
        self::assertStringContainsString("\n- [Eventlokale im Kanton Zürich](http://localhost/eventlokale/zuerich): 11 Locations\n", $body);
        self::assertStringContainsString('](http://localhost/eventlokale/zuerich/'.$data['town'].'): 5 Locations', $body);
        self::assertStringContainsString('](http://localhost/eventlokale/'.$data['building'].'): 6 Locations', $body);
        self::assertMatchesRegularExpression(
            '~\n- \[Schloss Seeblick\]\(http://localhost/eventlokal/'.DetailVenues::FULL_SLUG.'\.md\): \d{4} Zürich, Kanton Zürich, bis 300 Personen\n~',
            $body,
        );
        self::assertStringContainsString("\n- [Sitemap](http://localhost/sitemap.xml): ", $body);
    }

    public function test_a_liechtenstein_venue_is_noted_without_kanton(): void
    {
        DetailVenues::inCantonOnly('remise-vaduz', 'Remise Vaduz', 'li');

        self::assertStringContainsString('remise-vaduz.md): Liechtenstein, bis 80 Personen'."\n", $this->llmsTxt());
    }

    public function test_every_link_answers_200_and_an_unpublished_venue_is_not_listed(): void
    {
        $data = $this->seedPublicRoutes();
        $body = $this->llmsTxt();

        self::assertStringNotContainsString($data['gone'], $body);
        $this->client->request('GET', '/eventlokal/'.$data['gone'].'.md');
        self::assertResponseStatusCodeSame(410);

        preg_match_all('~\]\((http://localhost[^)]*)\)~', $body, $links);
        self::assertCount(\count(array_unique($links[1])), $links[1], 'no link twice');
        // Home, listing, sitemap and the two legal pages; Zürich, Stäfa and the castle page (Bern's one venue is unpublished); 11 venues.
        self::assertCount(19, $links[1]);
        foreach ($links[1] as $link) {
            $this->client->request('GET', $link);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $link);
        }
    }

    public function test_the_venue_list_is_sorted_by_name(): void
    {
        $this->seedPublicRoutes();

        preg_match_all('~^- \[(.+)\]\(http://localhost/eventlokal/~m', $this->llmsTxt(), $names);
        $sorted = $names[1];
        $collator = new \Collator('de');
        usort($sorted, static fn (string $a, string $b): int => (int) $collator->compare($a, $b));

        self::assertCount(11, $names[1]);
        self::assertSame($sorted, $names[1]);
    }

    public function test_the_links_take_the_scheme_of_the_request(): void
    {
        $this->seedPublicRoutes();
        $this->client->setServerParameter('HTTPS', 'on');

        $body = $this->llmsTxt();

        self::assertStringNotContainsString('http://', $body);
        self::assertStringContainsString('](https://localhost/eventlokal/'.DetailVenues::FULL_SLUG.'.md)', $body);
    }

    private function llmsTxt(): string
    {
        $this->client->request('GET', '/llms.txt');
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }
}
