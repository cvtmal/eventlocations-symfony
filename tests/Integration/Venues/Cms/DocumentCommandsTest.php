<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues\Cms;

use App\Factory\VenueDocumentFactory;
use App\Factory\VenueDocumentTranslationFactory;
use App\Factory\VenueFactory;
use App\Media\Exception\MissingOriginal;
use App\Media\MediaPaths;
use App\Tests\Support\Media\TestImages;
use App\Venues\Command\AddVenueDocument;
use App\Venues\Command\RemoveVenueDocument;
use App\Venues\Command\ReorderVenueDocuments;
use App\Venues\Command\RetitleVenueDocument;
use App\Venues\Exception\StaleEdit;
use App\Venues\Exception\VenueRowNotFound;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * AddVenueDocument, RetitleVenueDocument, ReorderVenueDocuments, RemoveVenueDocument (#25,
 * Steps 5.1–5.4, CMS-7, D19, D22, PF1).
 */
final class DocumentCommandsTest extends KernelTestCase
{
    use Factories;
    use HandlesVenueMedia;

    public function test_add_document_stores_the_original_publishes_the_pdf_and_purges_the_venue_only(): void
    {
        $venue = VenueFactory::createOne();
        $number = $venue->number();
        VenueDocumentFactory::createOne(['venue' => $venue, 'position' => 1]);
        $this->em->clear();
        $source = $this->uploadPdf();

        $id = $this->dispatch(new AddVenueDocument($number, $source, ['de' => ' Preisliste 2027 ']));
        self::assertIsString($id);

        $path = 'cms/'.$number.'/'.hash_file('sha256', $source).'.pdf';
        self::assertSame(
            ['path' => $path, 'size_bytes' => filesize($source), 'position' => 2],
            $this->connection->fetchAssociative('SELECT path, size_bytes, position FROM venue_documents WHERE id = ?', [$id]),
        );
        self::assertSame('Preisliste 2027', $this->connection->fetchOne('SELECT title FROM venue_document_translations WHERE venue_document_id = ?', [$id]));
        self::assertFileEquals($source, $this->paths->original($path));
        self::assertFileEquals($source, $this->paths->documentFile($number, $id));
        self::assertTrue($this->cmsEdited($number));
        self::assertSame([$number], $this->purger()->venues);
        self::assertSame(0, $this->purger()->listings);
    }

    public function test_the_same_pdf_again_or_a_missing_title_is_refused_and_writes_nothing(): void
    {
        $venue = VenueFactory::createOne();
        $number = $venue->number();
        $this->em->clear();
        $source = $this->uploadPdf();
        $this->dispatch(new AddVenueDocument($number, $source, ['de' => 'Preisliste']));
        $this->em->clear();

        $again = $this->violation(new AddVenueDocument($number, $this->uploadPdf(), ['de' => 'Nochmals']));
        self::assertSame('documents.error.already_uploaded', $again->translationKey());
        self::assertSame('file', $again->field);

        foreach ([[], ['de' => ' ']] as $titles) {
            $untitled = $this->violation(new AddVenueDocument($number, $this->uploadPdf('Andere'), $titles));
            self::assertSame('documents.error.title_required', $untitled->translationKey());
            self::assertSame('titles.de', $untitled->field);
        }
        self::assertInstanceOf(\InvalidArgumentException::class, $this->failure(new AddVenueDocument($number, $this->uploadPdf('Andere'), ['de' => 'Menü', 'xx' => 'Menu'])));

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_documents WHERE venue_id = ?', [$venue->id()]));
        self::assertSame(
            [$this->paths->original('cms/'.$number.'/'.hash_file('sha256', $source).'.pdf')],
            glob($this->paths->original('cms/'.$number).'/*') ?: [],
            'a refused upload stores no original',
        );
    }

    public function test_retitle_changes_the_title_and_refuses_a_stale_token(): void
    {
        $venue = VenueFactory::createOne();
        $document = VenueDocumentFactory::createOne(['venue' => $venue]);
        VenueDocumentTranslationFactory::createOne(['document' => $document, 'locale' => 'de', 'title' => 'Alt']);
        $this->ageRow('venue_documents', $document->id());
        $this->em->clear();
        $token = $this->rowToken('venue_documents', $document->id());

        $this->dispatch(new RetitleVenueDocument($venue->number(), $document->id(), $token, ['de' => 'Alt ']));
        self::assertFalse($this->cmsEdited($venue->number()), 'an unchanged title is a no-op');
        self::assertSame($token, $this->rowToken('venue_documents', $document->id()));

        $this->em->clear();
        $this->dispatch(new RetitleVenueDocument($venue->number(), $document->id(), $token, ['de' => 'Neu']));
        self::assertSame('Neu', $this->connection->fetchOne('SELECT title FROM venue_document_translations WHERE venue_document_id = ?', [$document->id()]));
        self::assertNotSame($token, $this->rowToken('venue_documents', $document->id()));
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(0, $this->purger()->listings);

        $this->em->clear();
        self::assertInstanceOf(StaleEdit::class, $this->failure(new RetitleVenueDocument($venue->number(), $document->id(), $token, ['de' => 'Zu spät'])));
        self::assertInstanceOf(VenueRowNotFound::class, $this->failure(new RetitleVenueDocument($venue->number(), VenueDocumentFactory::createOne()->id(), $token, ['de' => 'Fremd'])));
    }

    public function test_reorder_documents_persists_a_contiguous_order(): void
    {
        $venue = VenueFactory::createOne();
        $a = VenueDocumentFactory::createOne(['venue' => $venue, 'position' => 1])->id();
        $b = VenueDocumentFactory::createOne(['venue' => $venue, 'position' => 2])->id();
        $c = VenueDocumentFactory::createOne(['venue' => $venue, 'position' => 3])->id();
        $foreign = VenueDocumentFactory::createOne()->id();
        $this->em->clear();
        self::assertFalse($this->cmsEdited($venue->number()));

        $this->dispatch(new ReorderVenueDocuments($venue->number(), [$b, $c, $a]));
        self::assertSame([$b, $c, $a], $this->documents($venue->id()));
        self::assertSame([1, 2, 3], array_map(intval(...), $this->connection->fetchFirstColumn('SELECT position FROM venue_documents WHERE venue_id = ? ORDER BY position', [$venue->id()])));
        self::assertTrue($this->cmsEdited($venue->number()));
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(0, $this->purger()->listings);

        $this->em->clear();
        self::assertInstanceOf(StaleEdit::class, $this->failure(new ReorderVenueDocuments($venue->number(), [$b, $c, $foreign])));
        self::assertSame([$b, $c, $a], $this->documents($venue->id()));
        self::assertSame([$venue->number()], $this->purger()->venues, 'a refused order purges nothing');
    }

    public function test_remove_document_marks_the_venue_and_purges_only_it(): void
    {
        $venue = VenueFactory::createOne();
        $document = VenueDocumentFactory::createOne(['venue' => $venue])->id();
        $this->em->clear();
        self::assertFalse($this->cmsEdited($venue->number()));

        $this->dispatch(new RemoveVenueDocument($venue->number(), $document));

        self::assertTrue($this->cmsEdited($venue->number()));
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(0, $this->purger()->listings);
    }

    /**
     * The handler's clean-up: a test-only postFlush listener takes the stored PDF away, so publishing fails,
     * and leaves a half-written public file, which only revert() can remove.
     */
    public function test_a_publish_failure_removes_the_public_file_and_leaves_no_row(): void
    {
        $venue = VenueFactory::createOne();
        $number = $venue->number();
        $this->em->clear();
        $listener = new class($this->connection, $this->paths, $venue->id(), $number) {
            public ?string $halfWritten = null;

            public function __construct(
                private readonly Connection $connection,
                private readonly MediaPaths $paths,
                private readonly string $venueId,
                private readonly int $number,
            ) {
            }

            public function postFlush(): void
            {
                $row = $this->connection->fetchAssociative('SELECT id, path FROM venue_documents WHERE venue_id = ?', [$this->venueId]);
                if (false === $row) {
                    return;
                }
                unlink($this->paths->original((string) $row['path']));
                $this->halfWritten = $this->paths->documentFile($this->number, (string) $row['id']);
                TestImages::write(\dirname($this->halfWritten), basename($this->halfWritten), 'halb');
            }
        };
        $this->em->getEventManager()->addEventListener(Events::postFlush, $listener);

        $failure = $this->failure(new AddVenueDocument($number, $this->uploadPdf(), ['de' => 'Preisliste']));

        $this->em->getEventManager()->removeEventListener(Events::postFlush, $listener);
        self::assertInstanceOf(MissingOriginal::class, $failure);
        self::assertNotNull($listener->halfWritten);
        self::assertFileDoesNotExist($listener->halfWritten);
        self::assertSame([], $this->documents($venue->id()));
        self::assertFalse($this->cmsEdited($number));
        self::assertSame([], $this->purger()->venues);
    }

    public function test_remove_document_deletes_the_pdf_and_an_unused_original_but_keeps_a_shared_one(): void
    {
        $venue = VenueFactory::createOne();
        $number = $venue->number();
        $this->em->clear();
        $first = $this->dispatch(new AddVenueDocument($number, $this->uploadPdf('Eins'), ['de' => 'Eins']));
        $this->em->clear();
        $second = $this->dispatch(new AddVenueDocument($number, $shared = $this->uploadPdf('Zwei'), ['de' => 'Zwei']));
        self::assertIsString($first);
        self::assertIsString($second);
        $firstPath = (string) $this->connection->fetchOne('SELECT path FROM venue_documents WHERE id = ?', [$first]);
        $sharedPath = 'cms/'.$number.'/'.hash_file('sha256', $shared).'.pdf';

        $this->em->clear();
        $this->dispatch(new RemoveVenueDocument($number, $first));
        self::assertSame([$second], $this->documents($venue->id()));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT position FROM venue_documents WHERE id = ?', [$second]));
        self::assertFileDoesNotExist($this->paths->documentFile($number, $first));
        self::assertFileDoesNotExist($this->paths->original($firstPath));

        VenueDocumentFactory::createOne(['path' => $sharedPath]);
        $this->em->clear();
        $this->dispatch(new RemoveVenueDocument($number, $second));
        self::assertFileDoesNotExist($this->paths->documentFile($number, $second));
        self::assertFileExists($this->paths->original($sharedPath), 'a document of another venue uses it');
        self::assertSame(0, $this->purger()->listings);
    }

    /**
     * @return list<string>
     */
    private function documents(string $venueId): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn('SELECT id FROM venue_documents WHERE venue_id = ? ORDER BY position', [$venueId]);

        return $ids;
    }
}
