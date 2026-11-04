<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\VenueCoordinateFiller;
use App\Venues\VenueGeocoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * The text the filler asks Google for, read from the request body of a mocked client: a Swiss
 * canton carries " Schweiz", Liechtenstein does not (#46).
 */
final class VenueCoordinateFillerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/geocode-query-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->root.'/venues/*/venue.yaml') ?: []);
        array_map('unlink', glob($this->root.'/owner/*') ?: []);
        array_map('rmdir', glob($this->root.'/venues/*') ?: []);
        @rmdir($this->root.'/owner');
        @rmdir($this->root.'/venues');
        @rmdir($this->root);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function queries(): iterable
    {
        yield 'a Swiss canton' => ["name: 'Scheune Erfunden'\naddress:\n  canton: zh\n", 'Scheune Erfunden Zürich Schweiz'];
        yield 'Liechtenstein' => ["name: 'Schloss Erfunden'\naddress:\n  canton: li\n", 'Schloss Erfunden Liechtenstein'];
        yield 'a region text' => ["name: 'Hof Erfunden'\ntranslations:\n  de:\n    region: Emmental\n", 'Hof Erfunden Emmental Schweiz'];
    }

    #[DataProvider('queries')]
    public function test_the_query_names_the_venue_its_region_and_the_country(string $yaml, string $query): void
    {
        mkdir($this->root.'/venues/a-venue', 0775, true);
        file_put_contents($this->root.'/venues/a-venue/venue.yaml', $yaml);
        $asked = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$asked): JsonMockResponse {
            $body = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '', true, flags: \JSON_THROW_ON_ERROR);
            \assert(\is_array($body));
            $asked[] = $body['textQuery'] ?? null;

            return new JsonMockResponse(['places' => []]);
        });

        (new VenueCoordinateFiller(new VenueGeocoder($client, 'k'), new MockClock()))->fill($this->root, true);

        self::assertSame([$query], $asked);
    }
}
