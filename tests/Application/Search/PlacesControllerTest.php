<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Tests\Integration\Geo\ImportsTownDataset;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlacesControllerTest extends WebTestCase
{
    use ImportsTownDataset;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function test_text_suggests_the_canton_with_its_label_then_towns(): void
    {
        $places = $this->places('/_search/places?q=zue');

        // The label comes through the real translator and the geo catalogue (#3 carry-forward).
        self::assertSame(['type' => 'canton', 'canton' => 'zh', 'label' => 'Zürich'], $places[0]);
        $towns = array_values(array_filter($places, static fn (array $place): bool => 'town' === $place['type']));
        self::assertNotEmpty($towns);
        self::assertSame('Zürich', $towns[0]['name']);
        self::assertSame('zh', $towns[0]['canton']);
        self::assertIsString($towns[0]['id']);
        self::assertMatchesRegularExpression('/^\d{4}$/', (string) $towns[0]['zip']);
        self::assertSame(['type', 'id', 'name', 'zip', 'canton', 'lat', 'lng'], array_keys($towns[0]));
        // A whole-degree coordinate decodes as an int.
        self::assertIsNumeric($towns[0]['lat']);
        self::assertIsNumeric($towns[0]['lng']);
    }

    public function test_a_zip_suggests_its_town_first(): void
    {
        $first = $this->places('/_search/places?q=8712')[0];

        self::assertSame('town', $first['type']);
        self::assertSame('Stäfa', $first['name']);
        self::assertSame('8712', $first['zip']);
    }

    #[DataProvider('nothing')]
    public function test_input_without_suggestions_gives_an_empty_list(string $uri): void
    {
        self::assertSame([], $this->places($uri));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nothing(): iterable
    {
        yield 'non-latin' => ['/_search/places?q='.rawurlencode('日本')];
        yield 'empty' => ['/_search/places?q='];
        yield 'missing' => ['/_search/places'];
        yield 'array' => ['/_search/places?q[]=x'];
    }

    public function test_headers(): void
    {
        $this->client->request('GET', '/_search/places?q=zue');
        $response = $this->client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=3600', $cacheControl);
        self::assertStringContainsString('s-maxage=86400', $cacheControl);
        self::assertFalse($response->headers->has('Set-Cookie'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function places(string $uri): array
    {
        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertArrayHasKey('places', $json);
        self::assertIsList($json['places']);

        /* @var list<array<string, mixed>> */
        return $json['places'];
    }
}
