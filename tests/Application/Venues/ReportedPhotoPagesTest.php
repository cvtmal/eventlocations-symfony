<?php

declare(strict_types=1);

namespace App\Tests\Application\Venues;

use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Integration\Venues\Cms\HandlesVenueMedia;
use App\Tests\Support\Http\ThroughHttpCache;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Command\DecideModerationCase;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use App\Venues\ValueObject\ModerationDecision;
use App\Venues\ValueObject\PhotoGrant;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * G2's done-when, through the same HttpCache as production: a granted photo is on the cached detail page, a visitor
 * reports it on the public form, and within the same minute it is gone from the detail page (gallery, og:image,
 * JSON-LD), the listing, the Merkliste and the sitemap, with a receipt to the complainant, a notice to staff and the
 * audit rows. Staff decide `keep` and it is back; another complaint decided `remove` takes it for good.
 */
final class ReportedPhotoPagesTest extends WebTestCase
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
    private const string DETAIL = '/hochzeitslocation/seehaus-meldung';
    private const string LISTING = '/hochzeitslocations';
    private const string SITEMAP = '/sitemap-venues-1.xml';

    protected function setUp(): void
    {
        self::mockTime('2026-10-30 22:00:00 Europe/Zurich');
        $kernel = self::bootKernel();
        $this->setUpMedia();
        $this->startHttpCache($kernel);
        $pool = self::getContainer()->get('cache.content_report');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }

    protected function tearDown(): void
    {
        $this->stopHttpCache();
        $this->tearDownMedia();
    }

    public function test_a_reported_photo_leaves_every_public_page_within_a_minute_and_comes_back_on_keep(): void
    {
        $venue = $this->venue();
        $number = $venue->number();
        [$reported, $other] = [$this->grantedPhoto($number), $this->grantedPhoto($number)];
        foreach ($this->pages($number) as $page => $request) {
            $this->assertShows([$reported, $other], [], $this->through($request), $page);
        }
        $detail = $this->through(Request::create(self::DETAIL));
        self::assertSame('fresh', $detail->headers->get('X-Symfony-Cache'), 'the page comes from the cache before the complaint');
        self::assertSame($reported, $this->shareImage($detail));

        $started = hrtime(true);
        $response = $this->report($number, [$reported]);

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        $mails = array_values(array_filter(self::getMailerMessages(), static fn ($message): bool => $message instanceof Email));
        self::assertCount(2, $mails, 'a receipt and a staff notice');
        $recipients = array_map(static fn (Email $mail): string => $mail->getTo()[0]->getAddress(), $mails);
        $staff = self::getContainer()->getParameter('site.contact_email');
        self::assertIsString($staff);
        self::assertSame(['erika@example.ch', $staff], $recipients);
        self::assertStringContainsString('Das gemeldete Foto ist ab sofort verborgen', (string) $mails[0]->getHtmlBody());
        foreach ($this->pages($number) as $page => $request) {
            $after = $this->through($request);
            if ('merkliste' !== $page) {
                self::assertSame('miss/store', $after->headers->get('X-Symfony-Cache'), $page.': the complaint purged the cached page');
            }
            $this->assertShows([$other], [$reported], $after, $page);
        }
        $detail = $this->through(Request::create(self::DETAIL));
        self::assertSame($other, $this->shareImage($detail), 'og:image moves to the next public photo');
        self::assertSame([$other], $this->jsonLdImages($detail));
        self::assertLessThan(60.0, (hrtime(true) - $started) / 1e9, 'the complaint round-trips in under a minute');

        $caseId = (string) $this->connection->fetchOne('SELECT id FROM moderation_cases');
        self::assertSame(
            [['actor_type' => 'system', 'command' => 'App\Venues\Command\ReportContent', 'subject_id' => $caseId]],
            $this->connection->fetchAllAssociative("SELECT actor_type, command, subject_id FROM admin_audit_log WHERE subject_type = 'moderation_case'"),
        );

        $this->dispatch(new DecideModerationCase($caseId, ModerationDecision::Keep, null, self::ADMIN));

        foreach ($this->pages($number) as $page => $request) {
            $this->assertShows([$reported, $other], [], $this->through($request), $page.' after keep');
        }
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT count(*) FROM admin_audit_log WHERE subject_type = 'moderation_case'"));

        $this->report($number, [$reported]);
        $second = (string) $this->connection->fetchOne("SELECT id FROM moderation_cases WHERE status = 'open'");
        $this->dispatch(new DecideModerationCase($second, ModerationDecision::Remove, null, self::ADMIN));

        foreach ($this->pages($number) as $page => $request) {
            $this->assertShows([$other], [$reported], $this->through($request), $page.' after remove');
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_photos WHERE id = ?', [$reported]));
    }

    /**
     * @param list<string> $photoIds
     */
    private function report(int $number, array $photoIds): Response
    {
        return $this->through(Request::create('/inhalt-melden?location='.$number, 'POST', ['report' => [
            'kind' => 'rights',
            'photos' => $photoIds,
            'message' => 'Dieses Foto habe ich aufgenommen und nie freigegeben.',
            'name' => 'Erika Beispiel',
            'email' => 'erika@example.ch',
            'goodFaith' => '1',
            'source' => self::DETAIL,
            'website' => '',
            '_token' => 'csrf-token',
        ]], [], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']));
    }

    /**
     * @param list<string> $present photo ids the page names
     * @param list<string> $absent  photo ids it does not name anywhere
     */
    private function assertShows(array $present, array $absent, Response $response, string $page): void
    {
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $page);
        $html = (string) $response->getContent();
        foreach ($present as $id) {
            self::assertStringContainsString($id, $html, $page.' shows '.$id);
        }
        foreach ($absent as $id) {
            self::assertStringNotContainsString($id, $html, $page.' must not name '.$id);
        }
    }

    /**
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

    private function shareImage(Response $response): ?string
    {
        $image = new Crawler((string) $response->getContent())->filter('meta[property="og:image"]');

        return 0 < $image->count() && 1 === preg_match('#/media/\d+/([0-9a-f-]{36})-\d+\.jpg$#', (string) $image->attr('content'), $match) ? $match[1] : null;
    }

    /**
     * @return list<string> the photo ids in the `image` lists of the page's JSON-LD
     */
    private function jsonLdImages(Response $response): array
    {
        $ids = [];
        foreach (new Crawler((string) $response->getContent())->filter('script[type="application/ld+json"]') as $script) {
            preg_match_all('#/media/\d+/([0-9a-f-]{36})-\d+\.jpg#', $script->textContent, $matches);
            array_push($ids, ...$matches[1]);
        }

        return array_values(array_unique($ids));
    }

    private function grantedPhoto(int $number): string
    {
        return $this->addPhoto($number, $this->upload(), grant: new PhotoGrant(Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation))->photoId;
    }

    private function venue(): Venue
    {
        $venue = VenueFactory::createOne(['name' => 'Seehaus Meldung', 'town' => DetailVenues::town('Zürich')]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => 'seehaus-meldung', 'summary' => 'Eine Location am See.']);

        return $venue;
    }
}
