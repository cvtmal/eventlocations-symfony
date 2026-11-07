<?php

declare(strict_types=1);

namespace App\Tests\Application\Venues;

use App\Factory\RoomFactory;
use App\Factory\RoomTranslationFactory;
use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Integration\Venues\Cms\HandlesVenueMedia;
use App\Tests\Support\Http\ThroughHttpCache;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Command\GrantPhotoRights;
use App\Venues\Command\RemoveVenuePhoto;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use App\Venues\ValueObject\PhotoGrant;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * G1 in the HTML a visitor gets, through the same HttpCache as production: staff revoke a photo and
 * the detail page, the listing, the Merkliste and the sitemap stop naming it (the id is in every
 * `<picture>` URL, in `og:image`, in the JSON-LD and in the sitemap), and the cache does not keep
 * answering with the old page. A photo that was never granted is on none of them until it is.
 */
final class RevokedPhotoPagesTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use HandlesVenueMedia {
        setUp as private setUpMedia;
        tearDown as private tearDownMedia;
    }
    use ImportsTownDataset;
    use ThroughHttpCache;

    private const string ADMIN = '019a0000-0000-7000-8000-0000000000ad';
    private const string DETAIL = '/eventlokal/seehaus-html';
    private const string LISTING = '/eventlokale';
    private const string SITEMAP = '/sitemap-venues-1.xml';

    protected function setUp(): void
    {
        // Two hours before a Zurich midnight, so a cached listing's lifetime is known.
        self::mockTime('2026-10-30 22:00:00 Europe/Zurich');
        $kernel = self::bootKernel();
        $this->setUpMedia();
        $this->startHttpCache($kernel);
    }

    protected function tearDown(): void
    {
        $this->stopHttpCache();
        $this->tearDownMedia();
    }

    public function test_a_revoked_photo_leaves_every_public_page_and_the_cache_does_not_keep_it(): void
    {
        $venue = $this->venue();
        $number = $venue->number();
        [$first, $second, $third] = [$this->grantedPhoto($number), $this->grantedPhoto($number), $this->grantedPhoto($number)];
        $private = $this->addPhoto($number, $this->upload())->photoId;

        foreach ($this->pages($number) as $page => $request) {
            $response = $this->through($request);
            self::assertSame($this->isShared($page) ? 'miss/store' : 'miss', $response->headers->get('X-Symfony-Cache'), $page);
            $this->assertShows([$first, $second, $third], [$private], $response, $page);
        }
        $detail = $this->through(Request::create(self::DETAIL));
        self::assertSame('fresh', $detail->headers->get('X-Symfony-Cache'), 'the page was served from the cache before the revocation');
        self::assertSame($first, $this->shareImage($detail), 'og:image is the first public photo');

        $this->dispatch(new RemoveVenuePhoto($number, $second, Grantor::staff(self::ADMIN)));

        foreach ($this->pages($number) as $page => $request) {
            $response = $this->through($request);
            self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $page);
            if ($this->isShared($page)) {
                self::assertSame('miss/store', $response->headers->get('X-Symfony-Cache'), $page.': the purge reached the cached page');
            }
            $this->assertShows([$first, $third], [$second, $private], $response, $page);
        }

        $this->dispatch(new RemoveVenuePhoto($number, $first, Grantor::staff(self::ADMIN)));

        $detail = $this->through(Request::create(self::DETAIL));
        $this->assertShows([$third], [$first, $second, $private], $detail, 'detail');
        self::assertSame($third, $this->shareImage($detail), 'og:image moves to the next public photo');
        self::assertSame([$third], $this->jsonLdImages($detail));
    }

    public function test_the_last_photo_revoked_leaves_a_page_without_any_picture(): void
    {
        $venue = $this->venue();
        $number = $venue->number();
        $only = $this->grantedPhoto($number);
        self::assertSame([$only], $this->galleryIds($this->through(Request::create(self::DETAIL))));

        $this->dispatch(new RemoveVenuePhoto($number, $only, Grantor::staff(self::ADMIN)));

        $response = $this->through(Request::create(self::DETAIL));
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $crawler = new Crawler((string) $response->getContent());
        self::assertSame([], $this->galleryIds($response));
        // No photo of its own left: the page shares the brand's card, never the revoked file.
        self::assertSame('http://localhost/brand/og-default.png', $crawler->filter('meta[property="og:image"]')->attr('content'));
        self::assertSame('http://localhost/brand/og-default.png', $crawler->filter('meta[name="twitter:image"]')->attr('content'));
        self::assertSame([], $this->jsonLdImages($response));
        self::assertSame(0, substr_count((string) $this->through(Request::create(self::SITEMAP))->getContent(), '<image:image>'));
    }

    public function test_a_room_photo_revoked_leaves_the_room_without_its_picture(): void
    {
        $venue = $this->venue();
        $number = $venue->number();
        $room = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        RoomTranslationFactory::createOne(['room' => $room, 'name' => 'Rittersaal']);
        $staff = new PhotoGrant(Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation);
        $roomPhoto = $this->addPhoto($number, $this->upload(), $room->id(), grant: $staff)->photoId;
        $ungranted = $this->addPhoto($number, $this->upload(), $room->id())->photoId;
        $page = (string) $this->through(Request::create(self::DETAIL))->getContent();
        self::assertStringContainsString($roomPhoto, $page);
        self::assertStringNotContainsString($ungranted, $page);

        $this->dispatch(new RemoveVenuePhoto($number, $roomPhoto, Grantor::staff(self::ADMIN)));

        $response = $this->through(Request::create(self::DETAIL));
        self::assertStringNotContainsString($roomPhoto, (string) $response->getContent());
        self::assertStringNotContainsString($ungranted, (string) $response->getContent(), 'the next photo of the room has no grant either');
        self::assertStringContainsString('Rittersaal', (string) $response->getContent(), 'the room itself stays');
    }

    public function test_a_photo_that_was_never_granted_is_on_no_page_until_staff_grant_it(): void
    {
        $venue = $this->venue();
        $number = $venue->number();
        $photo = $this->addPhoto($number, $this->upload())->photoId;
        foreach ($this->pages($number) as $page => $request) {
            $this->assertShows([], [$photo], $this->through($request), $page);
        }

        $granted = $this->dispatch(new GrantPhotoRights($number, Grantor::manager(self::ADMIN), GrantEvidence::Declaration));

        self::assertSame(1, $granted);
        foreach ($this->pages($number) as $page => $request) {
            $response = $this->through($request);
            if ($this->isShared($page)) {
                self::assertSame('miss/store', $response->headers->get('X-Symfony-Cache'), $page.': the grant purged the cached page');
            }
            $this->assertShows([$photo], [], $response, $page);
        }
    }

    /**
     * @param list<string> $present photo ids the page names
     * @param list<string> $absent  photo ids it does not name anywhere
     */
    private function assertShows(array $present, array $absent, Response $response, string $page): void
    {
        $html = (string) $response->getContent();
        foreach ($present as $id) {
            self::assertStringContainsString($id, $html, $page.' shows '.$id);
        }
        foreach ($absent as $id) {
            self::assertStringNotContainsString($id, $html, $page.' must not name '.$id);
        }
    }

    /**
     * The pages that show the venue's photos, by name; the Merkliste carries the venue in its cookie.
     *
     * @return array<string, Request>
     */
    private function pages(int $number): array
    {
        return [
            'detail' => Request::create(self::DETAIL),
            'listing' => Request::create(self::LISTING),
            'merkliste' => Request::create('/merkliste', 'GET', [], ['merkliste' => (string) $number]),
            'sitemap' => Request::create(self::SITEMAP),
        ];
    }

    /**
     * Whether the page is stored in the shared cache; the Merkliste depends on the visitor's cookie and never is.
     */
    private function isShared(string $page): bool
    {
        return 'merkliste' !== $page;
    }

    /**
     * The photo id in the page's `og:image`.
     */
    private function shareImage(Response $response): ?string
    {
        $crawler = new Crawler((string) $response->getContent());
        $image = $crawler->filter('meta[property="og:image"]');
        if (0 === $image->count()) {
            return null;
        }

        return 1 === preg_match('#/media/\d+/([0-9a-f-]{36})-\d+\.jpg$#', (string) $image->attr('content'), $match) ? $match[1] : null;
    }

    /**
     * The photo ids in the `image` list of the page's JSON-LD.
     *
     * @return list<string>
     */
    private function jsonLdImages(Response $response): array
    {
        $crawler = new Crawler((string) $response->getContent());
        $ids = [];
        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $data = json_decode($script->textContent, true, flags: \JSON_THROW_ON_ERROR);
            \assert(\is_array($data));
            foreach (self::imagesOf($data) as $url) {
                if (1 === preg_match('#/media/\d+/([0-9a-f-]{36})-\d+\.jpg$#', $url, $match)) {
                    $ids[] = $match[1];
                }
            }
        }

        return $ids;
    }

    /**
     * @param array<mixed> $node a JSON-LD node or a list of nodes
     *
     * @return list<string>
     */
    private static function imagesOf(array $node): array
    {
        $urls = [];
        foreach ($node as $key => $value) {
            if ('image' === $key) {
                foreach ((array) $value as $url) {
                    if (\is_string($url)) {
                        $urls[] = $url;
                    }
                }
            } elseif (\is_array($value)) {
                $urls = [...$urls, ...self::imagesOf($value)];
            }
        }

        return $urls;
    }

    /**
     * The photo ids of the page's gallery slides, in order.
     *
     * @return list<string>
     */
    private function galleryIds(Response $response): array
    {
        $crawler = new Crawler((string) $response->getContent());

        return array_values(array_unique($crawler->filter('#bilder img, #bilder source')->each(static function (Crawler $node): string {
            return 1 === preg_match('#/media/\d+/([0-9a-f-]{36})-\d+\.#', (string) ($node->attr('src') ?? $node->attr('srcset')), $match) ? $match[1] : '';
        })));
    }

    private function grantedPhoto(int $number): string
    {
        return $this->addPhoto($number, $this->upload(), grant: new PhotoGrant(Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation))->photoId;
    }

    private function venue(): Venue
    {
        $venue = VenueFactory::createOne(['name' => 'Seehaus Html', 'town' => DetailVenues::town('Zürich')]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => 'seehaus-html', 'summary' => 'Eine Location am See.']);

        return $venue;
    }
}
