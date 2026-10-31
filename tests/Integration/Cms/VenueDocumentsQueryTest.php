<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cms;

use App\Cms\ReadModel\VenueDocumentsQuery;
use App\Cms\ReadModel\VenueIdQuery;
use App\Factory\VenueDocumentFactory;
use App\Factory\VenueDocumentTranslationFactory;
use App\Factory\VenueFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class VenueDocumentsQueryTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private Connection $connection;
    private VenueDocumentsQuery $query;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->query = new VenueDocumentsQuery($this->connection, new VenueIdQuery($this->connection));
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_an_unknown_venue_is_null_and_a_venue_without_documents_an_empty_list(): void
    {
        self::assertNull($this->query->forVenue(999_999_999));

        $venue = VenueFactory::new()->withoutLocation()->create();
        self::assertSame([], $this->query->forVenue($venue->number()));
    }

    public function test_documents_by_position_with_titles_size_url_and_token(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        $second = VenueDocumentFactory::createOne(['venue' => $venue, 'position' => 2, 'sizeBytes' => 2048]);
        $first = VenueDocumentFactory::createOne(['venue' => $venue, 'position' => 1, 'sizeBytes' => 1024]);
        VenueDocumentTranslationFactory::createOne(['document' => $first, 'locale' => 'de', 'title' => 'Preisliste']);
        VenueDocumentTranslationFactory::createOne(['document' => $first, 'locale' => 'en', 'title' => 'Price list']);
        VenueDocumentFactory::createOne(['position' => 1]);
        $this->connection->executeStatement("UPDATE venue_documents SET updated_at = '2026-10-03 10:00:00+00' WHERE id = :id", ['id' => $first->id()]);

        $documents = $this->query->forVenue($venue->number());

        self::assertNotNull($documents);
        self::assertSame([$first->id(), $second->id()], array_map(static fn ($document): string => $document->id, $documents));
        self::assertSame(['de' => 'Preisliste', 'en' => 'Price list'], $documents[0]->titles);
        self::assertSame([1, 1024, \sprintf('/media/%d/%s.pdf', $venue->number(), $first->id())], [$documents[0]->position, $documents[0]->sizeBytes, $documents[0]->url]);
        self::assertSame((string) (new \DateTimeImmutable('2026-10-03 10:00:00+00'))->getTimestamp(), $documents[0]->token);
        self::assertSame('Price list', $documents[0]->title('en', 'de'));
        self::assertSame('Preisliste', $documents[0]->title('fr', 'de'));
        self::assertSame('', $documents[1]->title('de', 'de'));
    }

    public function test_one_document_only_of_its_own_venue(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        $document = VenueDocumentFactory::createOne(['venue' => $venue]);
        $foreign = VenueDocumentFactory::createOne([]);

        self::assertSame($document->id(), $this->query->document($venue->number(), $document->id())?->id);
        self::assertNull($this->query->document($venue->number(), $foreign->id()));
        self::assertNull($this->query->document($venue->number(), 'not-a-uuid'));
        self::assertNull($this->query->document(999_999_999, $document->id()));
    }
}
