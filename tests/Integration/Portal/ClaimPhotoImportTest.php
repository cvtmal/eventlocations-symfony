<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Cms\VenuePhotoUpload;
use App\Factory\PhotoCandidateFactory;
use App\Factory\SourceRecordFactory;
use App\Factory\VenueFactory;
use App\Portal\ClaimPhotoImport;
use App\Shared\Http\FetchPolicy;
use App\Shared\Http\SafeFetcher;
use App\Tests\Integration\Venues\Cms\HandlesVenueMedia;
use App\Tests\Support\Http\StubHostResolver;
use App\Tests\Support\Media\TestImages;
use App\Venues\ReadModel\PhotoCandidateQuery;
use App\Venues\ValueObject\CandidateOrigin;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use App\Venues\ValueObject\ImageCandidate;
use App\Venues\ValueObject\PhotoGrant;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * G6: a ticked candidate is fetched through SafeFetcher, checked like an upload and added with the claimant's
 * declaration grant; one that fails stays a candidate, and the ones left out are rejected.
 */
final class ClaimPhotoImportTest extends KernelTestCase
{
    use Factories;
    use HandlesVenueMedia;

    private const string SITE = 'https://www.remise-am-bach.example/';

    /** @var list<string> */
    private array $requested = [];

    public function test_a_good_photo_is_added_with_a_grant_a_bad_one_stays_and_the_rest_are_rejected(): void
    {
        $venue = VenueFactory::createOne();
        $record = SourceRecordFactory::createOne(['venue' => $venue, 'url' => self::SITE, 'report' => ['final_url' => self::SITE]]);
        foreach (['saal.jpg', 'seite.jpg', 'bach.jpg', 'intern.jpg'] as $position => $name) {
            PhotoCandidateFactory::createOne(['sourceRecord' => $record, 'position' => $position + 1, 'image' => new ImageCandidate(self::SITE.$name, self::SITE, CandidateOrigin::Img)]);
        }
        $rows = $this->query()->open($venue->number());
        self::assertCount(4, $rows);
        [$saal, $seite, $bach, $intern] = $rows;
        $resolver = (new StubHostResolver())->byDefault(StubHostResolver::PUBLIC_V4);

        $failed = $this->import([
            self::SITE.'saal.jpg' => [200, TestImages::jpeg(1600, 900), ['content-type' => 'image/jpeg']],
            self::SITE.'seite.jpg' => [200, '<html></html>', ['content-type' => 'text/html']],
        ], $resolver)->import(
            $venue->number(),
            [$saal, $seite],
            [$bach, $intern],
            new PhotoGrant(Grantor::manager('01a1b7c4-0000-7000-8000-000000000001'), GrantEvidence::Declaration),
        );

        self::assertSame(1, $failed);
        self::assertSame([self::SITE.'saal.jpg', self::SITE.'seite.jpg'], $this->requested, 'only the ticked ones are fetched');
        self::assertSame(
            [['evidence' => 'declaration', 'grantor_type' => 'manager']],
            $this->connection->fetchAllAssociative('SELECT g.evidence, g.grantor_type FROM media_rights_grants g JOIN venue_photos p ON p.id = g.venue_photo_id WHERE p.venue_id = ? AND g.revoked_at IS NULL', [$venue->id()]),
        );
        self::assertSame(
            ['approved', 'candidate', 'rejected', 'rejected'],
            array_map(fn ($row): string => (string) $this->connection->fetchOne('SELECT status FROM photo_candidates WHERE id = ?', [$row->id]), [$saal, $seite, $bach, $intern]),
        );
        self::assertSame([$seite->id], array_map(static fn ($row): string => $row->id, $this->query()->open($venue->number())), 'only the failed one is offered again');
    }

    /**
     * @param array<string, array{int, string, array<string, string>}> $routes
     */
    private function import(array $routes, StubHostResolver $resolver): ClaimPhotoImport
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($routes): MockResponse {
            $this->requested[] = $url;
            [$status, $body, $headers] = $routes[$url] ?? [404, 'not found', ['content-type' => 'text/html']];

            return new MockResponse($body, ['http_code' => $status, 'response_headers' => $headers]);
        });
        $container = self::getContainer();
        $upload = $container->get(VenuePhotoUpload::class);
        $validator = $container->get(ValidatorInterface::class);
        $bus = $container->get(MessageBusInterface::class);
        self::assertInstanceOf(VenuePhotoUpload::class, $upload);
        self::assertInstanceOf(ValidatorInterface::class, $validator);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return new ClaimPhotoImport(
            new SafeFetcher($client, $resolver, new MockClock('2026-11-07 10:00:00'), 'test-agent', policy: new FetchPolicy()),
            $upload,
            $validator,
            $bus,
            new NullLogger(),
        );
    }

    private function query(): PhotoCandidateQuery
    {
        $query = self::getContainer()->get(PhotoCandidateQuery::class);
        self::assertInstanceOf(PhotoCandidateQuery::class, $query);

        return $query;
    }
}
