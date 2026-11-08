<?php

declare(strict_types=1);

namespace App\Venues;

use App\Shared\Exception\FetchRefused;
use App\Shared\Http\FetchLimits;
use App\Shared\Http\HostResolver;
use App\Shared\Http\SafeFetcher;
use App\Venues\ValueObject\GooglePhotoImage;
use App\Venues\ValueObject\GooglePlacePhoto;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google Places photos of a venue without public photos of its own (D12, places-photos): our server asks the
 * Places API (New) and passes the bytes on, so the visitor's browser never contacts Google, and Google never
 * learns the visitor's IP address or the page they are on. Nothing is stored or cached: no file, no cache pool, no
 * HTTP cache (the controller answers `no-store`).
 *
 * - The key is `GOOGLE_PLACES_PHOTOS_KEY`, a server key that never reaches a page. Empty: `enabled()` is false,
 *   the page renders no section and no request goes out.
 * - Every Places call (Place Details, photo media) first takes one from the site-wide daily cap, limiter
 *   `google_places` (`google_places.daily_requests`, config/packages/rate_limiter.yaml), so a crawler cannot run up
 *   the Places bill. Over the cap the answer is "no photos", and the page keeps F6's placeholder.
 * - Every request goes through SafeFetcher with small caps of its own; the key travels as a header to
 *   places.googleapis.com only (`FetchSession::resource()` drops it on a redirect, and the image itself comes from
 *   the `photoUri` Google answers with, without the key).
 * - A refusal or an error of Google's is "no photos" too, logged by its reason only (no URL, no key).
 */
final class GooglePlacesPhotos
{
    /** Photos per venue: the strip of the detail page. Each one shown costs a media call and holds a PHP worker. */
    public const int MAX_PHOTOS = 4;

    /** The strip is 9.5 rem high on a phone and 12 rem from md on: 480 px covers 2x screens. */
    public const int HEIGHT_PX = 480;

    private const string API = 'https://places.googleapis.com/v1/';

    private const array IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private readonly SafeFetcher $fetcher;

    public function __construct(
        HttpClientInterface $httpClient,
        HostResolver $resolver,
        ClockInterface $clock,
        private readonly RateLimiterFactoryInterface $googlePlacesLimiter,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(string:default::GOOGLE_PLACES_PHOTOS_KEY)%')]
        private readonly string $apiKey,
        #[Autowire('deine-eventlocation.ch places-photos (+mailto:%site.contact_email%)')]
        string $userAgent,
    ) {
        // A visitor's image holds a PHP worker while it waits for Google, so the caps are tight: when Google is slow
        // the photo is dropped and the placeholder stays, rather than the site running out of workers (nginx also
        // limits the concurrent requests of these routes). A photo at 480 px is well under 1 MB.
        $this->fetcher = new SafeFetcher($httpClient, $resolver, $clock, $userAgent, new FetchLimits(
            maxBytesPerResponse: 1_500_000,
            maxTotalBytes: 1_600_000,
            maxRedirects: 2,
            maxPages: 2,
            idleTimeoutSeconds: 2.0,
            requestSeconds: 3.0,
            totalSeconds: 5.0,
            minDelaySeconds: 0.0,
        ));
    }

    public function enabled(): bool
    {
        return '' !== $this->apiKey;
    }

    /**
     * Up to MAX_PHOTOS photos of the place, each with its uploader's name; [] without a key, over the cap, or when
     * Google answers nothing usable.
     *
     * @return list<GooglePlacePhoto>
     */
    public function photos(string $placeId): array
    {
        if (!$this->enabled() || !$this->spend()) {
            return [];
        }

        try {
            $result = $this->fetcher->open()->resource(
                self::API.'places/'.rawurlencode($placeId),
                ['application/json'],
                ['X-Goog-Api-Key' => $this->apiKey, 'X-Goog-FieldMask' => 'photos'],
            );
        } catch (FetchRefused $e) {
            $this->failed('details', $e);

            return [];
        }

        $entries = self::json($result->body)['photos'] ?? [];
        $entries = \is_array($entries) ? $entries : [];
        $photos = [];
        foreach ($entries as $entry) {
            $photo = GooglePlacePhoto::fromApi($placeId, $entry);
            if (null !== $photo) {
                $photos[] = $photo;
            }
            if (\count($photos) >= self::MAX_PHOTOS) {
                break;
            }
        }
        if ([] === $photos && [] !== $entries) {
            // Every photo was of another place (Google moved the place to a new ID) or named no uploader.
            $this->logger->warning('Google Places photos: none of {count} photos is usable; check the place ID.', ['count' => \count($entries)]);
        }

        return $photos;
    }

    /**
     * The bytes of one photo of the place at HEIGHT_PX; null without a key, over the cap, or on any failure (an
     * expired name is Google's 404).
     */
    public function image(string $placeId, string $reference): ?GooglePhotoImage
    {
        if (!$this->enabled() || 1 !== preg_match('/\A'.GooglePlacePhoto::REFERENCE_REQUIREMENT.'\z/', $reference) || !$this->spend()) {
            return null;
        }

        $session = $this->fetcher->open();
        try {
            $media = $session->resource(
                self::API.'places/'.rawurlencode($placeId).'/photos/'.$reference.'/media?maxHeightPx='.self::HEIGHT_PX.'&skipHttpRedirect=true',
                ['application/json'],
                ['X-Goog-Api-Key' => $this->apiKey],
            );
            $photoUri = self::json($media->body)['photoUri'] ?? null;
            if (!\is_string($photoUri) || 1 !== preg_match('~\Ahttps://[a-z0-9-]+\.googleusercontent\.com/~', $photoUri)) {
                $this->logger->warning('Google Places photo: the media answer named no photoUri.');

                return null;
            }
            $image = $session->resource($photoUri, self::IMAGE_TYPES);
        } catch (FetchRefused $e) {
            $this->failed('media', $e);

            return null;
        }

        return new GooglePhotoImage($image->body, $image->contentType);
    }

    private function spend(): bool
    {
        if ($this->googlePlacesLimiter->create('site')->consume()->isAccepted()) {
            return true;
        }
        $this->logger->notice('Google Places photos: the daily cap is used up, the page shows the placeholder.');

        return false;
    }

    private function failed(string $call, FetchRefused $e): void
    {
        // The reason only: the detail could quote a URL, and none of ours may end up in a log.
        $this->logger->warning('Google Places photos: the {call} request was refused ({reason}).', ['call' => $call, 'reason' => $e->reason->value]);
    }

    /**
     * @return array<mixed>
     */
    private static function json(string $body): array
    {
        try {
            $data = json_decode($body, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($data) ? $data : [];
    }
}
