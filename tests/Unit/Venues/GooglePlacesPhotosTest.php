<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Tests\Support\Http\StubHostResolver;
use App\Venues\GooglePlacesPhotos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * D12, places-photos: the Places API (New) calls behind the detail page's Google photos, against a fake HTTP
 * client and a stub DNS, so no test reaches Google.
 */
final class GooglePlacesPhotosTest extends TestCase
{
    private const string PLACE = 'ChIJ_test-place_1234';
    private const string DETAILS = 'https://places.googleapis.com/v1/places/'.self::PLACE;
    private const string MEDIA = 'https://places.googleapis.com/v1/places/'.self::PLACE.'/photos/REF_one/media?maxHeightPx=480&skipHttpRedirect=true';
    private const string IMAGE = 'https://lh3.googleusercontent.com/place-photos/abc=s480';

    /** @var list<array{url: string, headers: array<string, list<string>>}> */
    private array $requests = [];

    private BufferingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new BufferingLogger();
    }

    protected function tearDown(): void
    {
        // BufferingLogger prints what is left when it is destroyed; the warnings are expected here.
        $this->logger->cleanLogs();
    }

    /**
     * @param array<string, array{int, string, string}> $routes by URL: status, body, content type
     */
    private function photos(array $routes, string $key = 'server-key', int $cap = 50): GooglePlacesPhotos
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($routes): MockResponse {
            $this->requests[] = ['url' => $url, 'headers' => $options['normalized_headers']];
            [$status, $body, $type] = $routes[$url] ?? [404, '{}', 'application/json'];

            return new MockResponse($body, ['http_code' => $status, 'response_headers' => ['content-type' => $type]]);
        });
        $limiter = new RateLimiterFactory(['id' => 'google_places', 'policy' => 'fixed_window', 'limit' => $cap, 'interval' => '1 day'], new InMemoryStorage());

        return new GooglePlacesPhotos(
            $client,
            (new StubHostResolver())->byDefault(StubHostResolver::PUBLIC_V4),
            new MockClock('2026-11-08 10:00:00'),
            $limiter,
            $this->logger,
            $key,
            'test-agent',
        );
    }

    /**
     * @return array{int, string, string}
     */
    private static function details(): array
    {
        $photo = static fn (string $reference, array $authors, array $extra = []): array => [
            'name' => 'places/'.self::PLACE.'/photos/'.$reference,
            'widthPx' => 4032,
            'heightPx' => 3024,
            'authorAttributions' => $authors,
        ] + $extra;
        $anna = ['displayName' => 'Anna Muster', 'uri' => 'https://maps.google.com/maps/contrib/1', 'photoUri' => 'https://lh3.googleusercontent.com/a/x'];

        return [200, (string) json_encode(['photos' => [
            $photo('REF_one', [$anna], ['googleMapsUri' => 'https://www.google.com/maps/place//data=1']),
            $photo('REF_nobody', []),
            ['name' => 'places/ChIJ_other_place_99/photos/REF_foreign', 'authorAttributions' => [$anna]],
            $photo('REF_js', [['displayName' => 'Ben', 'uri' => 'javascript:alert(1)']], ['googleMapsUri' => 'http://www.google.com/maps']),
            $photo('REF_3', [$anna]),
            $photo('REF_4', [$anna]),
            $photo('REF_5', [$anna]),
            $photo('REF_6', [$anna]),
        ]]), 'application/json; charset=utf-8'];
    }

    public function test_without_a_key_nothing_goes_out(): void
    {
        $google = $this->photos([self::DETAILS => self::details()], key: '');

        self::assertFalse($google->enabled());
        self::assertSame([], $google->photos(self::PLACE));
        self::assertNull($google->image(self::PLACE, 'REF_one'));
        self::assertSame([], $this->requests);
    }

    public function test_the_photos_carry_their_uploader_and_only_https_links(): void
    {
        $photos = $this->photos([self::DETAILS => self::details()])->photos(self::PLACE);

        self::assertSame(['REF_one', 'REF_js', 'REF_3', 'REF_4'], array_map(static fn ($photo): string => $photo->reference, $photos), 'no photo without an uploader, none of another place, at most four');
        self::assertSame('Anna Muster', $photos[0]->author);
        self::assertSame('https://maps.google.com/maps/contrib/1', $photos[0]->authorUri);
        self::assertSame('https://www.google.com/maps/place//data=1', $photos[0]->mapsUri);
        self::assertSame([4032, 3024], [$photos[0]->width, $photos[0]->height]);
        self::assertSame('Ben', $photos[1]->author);
        self::assertNull($photos[1]->authorUri, 'a javascript: link never reaches an href');
        self::assertNull($photos[1]->mapsUri, 'http is not https');
    }

    public function test_the_details_call_sends_the_key_as_a_header_and_asks_for_photos_only(): void
    {
        $this->photos([self::DETAILS => self::details()])->photos(self::PLACE);

        self::assertCount(1, $this->requests);
        self::assertSame(self::DETAILS, $this->requests[0]['url'], 'the key is not in the URL');
        self::assertSame(['X-Goog-Api-Key: server-key'], $this->requests[0]['headers']['x-goog-api-key']);
        self::assertSame(['X-Goog-FieldMask: photos'], $this->requests[0]['headers']['x-goog-fieldmask']);
    }

    public function test_an_image_is_the_bytes_of_the_photo_uri_fetched_without_the_key(): void
    {
        $image = $this->photos([
            self::MEDIA => [200, (string) json_encode(['name' => 'places/'.self::PLACE.'/photos/REF_one/media', 'photoUri' => self::IMAGE]), 'application/json'],
            self::IMAGE => [200, 'JPEGBYTES', 'image/jpeg'],
        ])->image(self::PLACE, 'REF_one');

        self::assertNotNull($image);
        self::assertSame('JPEGBYTES', $image->bytes);
        self::assertSame('image/jpeg', $image->contentType);
        self::assertSame([self::MEDIA, self::IMAGE], array_column($this->requests, 'url'));
        self::assertArrayHasKey('x-goog-api-key', $this->requests[0]['headers']);
        self::assertArrayNotHasKey('x-goog-api-key', $this->requests[1]['headers'], 'the key goes to the Places API only');
    }

    public function test_a_reference_with_path_characters_is_refused_before_any_request(): void
    {
        $google = $this->photos([]);

        self::assertNull($google->image(self::PLACE, '../../other'));
        self::assertNull($google->image(self::PLACE, 'a/b'));
        self::assertSame([], $this->requests);
    }

    public function test_google_refusing_or_answering_nonsense_is_no_photos(): void
    {
        self::assertSame([], $this->photos([self::DETAILS => [403, '{"error":{}}', 'application/json']])->photos(self::PLACE));
        self::assertSame([], $this->photos([self::DETAILS => [200, 'not json', 'application/json']])->photos(self::PLACE));
        self::assertSame([], $this->photos([self::DETAILS => [200, '{"photos":"x"}', 'application/json']])->photos(self::PLACE));
        self::assertNull($this->photos([self::MEDIA => [404, '{}', 'application/json']])->image(self::PLACE, 'REF_one'), 'an expired photo name');
        self::assertNull($this->photos([self::MEDIA => [200, '{"photoUri":"http://lh3.googleusercontent.com/x"}', 'application/json']])->image(self::PLACE, 'REF_one'));
        self::assertNull($this->photos([self::MEDIA => [200, '{"photoUri":"https://evil.example/lh3.googleusercontent.com/x"}', 'application/json']])->image(self::PLACE, 'REF_one'), 'only Google\'s image host');
        self::assertNull($this->photos([
            self::MEDIA => [200, (string) json_encode(['photoUri' => self::IMAGE]), 'application/json'],
            self::IMAGE => [200, '<html></html>', 'text/html'],
        ])->image(self::PLACE, 'REF_one'), 'only an image type passes');
    }

    public function test_the_daily_cap_stops_every_call_once_it_is_used_up(): void
    {
        $google = $this->photos([
            self::DETAILS => self::details(),
            self::MEDIA => [200, (string) json_encode(['photoUri' => self::IMAGE]), 'application/json'],
            self::IMAGE => [200, 'JPEGBYTES', 'image/jpeg'],
        ], cap: 2);

        self::assertNotSame([], $google->photos(self::PLACE));
        self::assertNotNull($google->image(self::PLACE, 'REF_one'));
        $made = \count($this->requests);

        self::assertSame([], $google->photos(self::PLACE));
        self::assertNull($google->image(self::PLACE, 'REF_one'));
        self::assertCount($made, $this->requests, 'over the cap no request goes to Google');
    }

    public function test_a_log_line_names_the_reason_never_the_key_or_a_url(): void
    {
        $this->photos([self::DETAILS => [403, '{"error":{"message":"API key server-key invalid"}}', 'application/json']])->photos(self::PLACE);
        $this->photos([self::DETAILS => [200, (string) json_encode(['photos' => [['name' => 'places/ChIJ_moved_place_1/photos/R', 'authorAttributions' => [['displayName' => 'A']]]]]), 'application/json']])->photos(self::PLACE);

        $logs = $this->logger->cleanLogs();
        self::assertCount(2, $logs);
        self::assertStringContainsString('{reason}', $logs[0][1]);
        self::assertSame(['call' => 'details', 'reason' => 'http_status'], $logs[0][2]);
        self::assertStringContainsString('check the place ID', $logs[1][1]);
        $all = (string) json_encode($logs);
        foreach (['server-key', 'googleapis', self::PLACE] as $secret) {
            self::assertStringNotContainsString($secret, $all, $secret);
        }
    }
}
