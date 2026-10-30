<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Media\ValueObject\ImageVariants;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Photos and documents through app:venues:import: content-addressed originals, variants
 * only for new files, ids kept for unchanged files, files of removed rows deleted.
 */
final class ImportContentMediaTest extends KernelTestCase
{
    use RunsContentImport;

    private const string KEY = 'schloss-beispiel';

    public function test_creates_variants_originals_documents_and_alt_texts(): void
    {
        $this->venueWithMedia();

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertStringContainsString('Photos: +4 −0. Documents: +1 −0.', $this->display());
        $number = $this->venueNumber(self::KEY);
        $photos = $this->photos();
        self::assertCount(4, $photos);
        foreach ($photos as $photo) {
            self::assertMatchesRegularExpression('#^content/schloss-beispiel/[0-9a-f]{64}\.jpg$#', $photo['original_path']);
            self::assertFileExists($this->paths->original($photo['original_path']));
            $variants = ImageVariants::fromArray(json_decode($photo['variants'], true, flags: \JSON_THROW_ON_ERROR));
            foreach ($variants->widths as $width) {
                foreach ($variants->formats as $format) {
                    self::assertFileExists($this->paths->variantFile($number, $photo['id'], $width, $format));
                }
            }
        }
        self::assertSame([[null, 1], [null, 2], [null, 3], ['rittersaal', 1]], array_map(static fn (array $p): array => [$p['room_key'], $p['position']], $photos));
        self::assertSame(['Aussenansicht'], $this->connection->fetchFirstColumn('SELECT t.alt FROM venue_photo_translations t JOIN venue_photos p ON p.id = t.venue_photo_id WHERE p.position = 1 AND p.room_id IS NULL'));

        $document = $this->connection->fetchAssociative('SELECT d.id, d.path, d.size_bytes, t.title FROM venue_documents d JOIN venue_document_translations t ON t.venue_document_id = d.id');
        self::assertIsArray($document);
        self::assertMatchesRegularExpression('#^content/schloss-beispiel/[0-9a-f]{64}\.pdf$#', $document['path']);
        self::assertSame('Preisliste', $document['title']);
        self::assertFileExists($this->paths->documentFile($number, $document['id']));
        self::assertSame($document['size_bytes'], filesize($this->paths->documentFile($number, $document['id'])));
    }

    public function test_a_changed_photo_gets_a_new_id_and_its_old_files_go(): void
    {
        $this->venueWithMedia();
        $this->import();
        $number = $this->venueNumber(self::KEY);
        [$first, $second] = $this->photos();
        $this->ageFiles();

        $this->content->file(self::KEY, 'photos/b.jpg', self::image(20));
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertStringContainsString('Photos: +1 −1.', $this->display());
        $after = $this->photos();
        self::assertSame($first['id'], $after[0]['id']);
        self::assertNotSame($second['id'], $after[1]['id']);
        self::assertSame(2, $after[1]['position']);
        self::assertSame([], glob($this->paths->photoDirectory($number).'/'.$second['id'].'-*') ?: []);
        self::assertFileDoesNotExist($this->paths->original($second['original_path']));
        self::assertNotEmpty(glob($this->paths->photoDirectory($number).'/'.$after[1]['id'].'-*') ?: []);
        foreach (glob($this->paths->photoDirectory($number).'/'.$first['id'].'-*') ?: [] as $file) {
            self::assertSame(1_000_000_000, filemtime($file), $file);
        }
    }

    public function test_reordering_and_renaming_files_regenerates_nothing(): void
    {
        $this->venueWithMedia();
        $this->import();
        $before = $this->photos();
        $this->ageFiles();
        $files = $this->fileTimes();

        rename($this->content->folder(self::KEY).'/photos/a.jpg', $this->content->folder(self::KEY).'/photos/z.jpg');
        $this->content->edit(self::KEY, static function (array $data): array {
            \assert(\is_array($data['photos']));
            [$a, $b, $c] = $data['photos'];
            \assert(\is_array($a));
            $a['file'] = 'z.jpg';
            $data['photos'] = [$c, $a, $b];

            return $data;
        });
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertStringContainsString('updated    schloss-beispiel', $this->display());
        self::assertStringContainsString('Photos: +0 −0.', $this->display());
        $after = $this->photos();
        $positions = array_column($after, 'position', 'id');
        self::assertSame([$before[2]['id'] => 1, $before[0]['id'] => 2, $before[1]['id'] => 3], array_intersect_key($positions, array_flip([$before[0]['id'], $before[1]['id'], $before[2]['id']])));
        self::assertSame($files, $this->fileTimes());
        self::assertSame(['Aussenansicht'], $this->connection->fetchFirstColumn('SELECT t.alt FROM venue_photo_translations t JOIN venue_photos p ON p.id = t.venue_photo_id WHERE p.id = ?', [$before[0]['id']]));
    }

    public function test_removed_photos_and_documents_lose_their_rows_and_files(): void
    {
        $this->venueWithMedia();
        $this->import();
        $number = $this->venueNumber(self::KEY);
        [, , $third] = $this->photos();
        $document = $this->connection->fetchAssociative('SELECT id, path FROM venue_documents');
        self::assertIsArray($document);

        unlink($this->content->folder(self::KEY).'/photos/c.jpg');
        unlink($this->content->folder(self::KEY).'/documents/preise.pdf');
        $this->content->edit(self::KEY, static function (array $data): array {
            \assert(\is_array($data['photos']));
            $data['photos'] = \array_slice($data['photos'], 0, 2);
            unset($data['documents']);

            return $data;
        });
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertStringContainsString('Photos: +0 −1. Documents: +0 −1.', $this->display());
        self::assertSame(0, $this->rows('venue_photos WHERE id = ?', [$third['id']]));
        self::assertSame(0, $this->rows('venue_documents'));
        self::assertSame([], glob($this->paths->photoDirectory($number).'/'.$third['id'].'-*') ?: []);
        self::assertFileDoesNotExist($this->paths->original($third['original_path']));
        self::assertFileDoesNotExist($this->paths->documentFile($number, $document['id']));
        self::assertFileDoesNotExist($this->paths->original($document['path']));
    }

    public function test_a_room_inserted_at_the_top_keeps_room_and_room_photo_ids(): void
    {
        $this->venueWithMedia();
        $this->import();
        $roomId = $this->connection->fetchOne("SELECT id FROM rooms WHERE content_key = 'rittersaal'");
        $roomPhotoId = $this->connection->fetchOne('SELECT id FROM venue_photos WHERE room_id IS NOT NULL');

        $this->content->edit(self::KEY, static function (array $data): array {
            \assert(\is_array($data['rooms']));
            $data['rooms'] = [['key' => 'foyer', 'translations' => ['de' => ['name' => 'Foyer']]], ...$data['rooms']];

            return $data;
        });
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertSame([['content_key' => 'foyer', 'position' => 1], ['content_key' => 'rittersaal', 'position' => 2]], $this->connection->fetchAllAssociative('SELECT content_key, position FROM rooms ORDER BY position'));
        self::assertSame($roomId, $this->connection->fetchOne("SELECT id FROM rooms WHERE content_key = 'rittersaal'"));
        self::assertSame($roomPhotoId, $this->connection->fetchOne('SELECT id FROM venue_photos WHERE room_id IS NOT NULL'));
        self::assertSame('Rittersaal', $this->connection->fetchOne('SELECT name FROM room_translations WHERE room_id = ?', [$roomId]));
        self::assertSame(2, $this->connection->fetchOne('SELECT room_count FROM venues WHERE content_key = ?', [self::KEY]));

        // Removing the room removes it and its photo.
        $this->content->remove(self::KEY);
        $this->content->venue(self::KEY, ['rooms' => [['key' => 'foyer', 'translations' => ['de' => ['name' => 'Foyer']]]]], 'Schloss Beispiel');
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        self::assertSame(['foyer'], $this->connection->fetchFirstColumn('SELECT content_key FROM rooms'));
        self::assertSame(0, $this->rows('venue_photos'));
    }

    public function test_the_same_file_in_the_gallery_and_in_a_room_is_one_original(): void
    {
        $this->content->venue(self::KEY, ['rooms' => [['key' => 'saal', 'translations' => ['de' => ['name' => 'Saal']]]]]);
        $this->content->photo(self::KEY, 'a.jpg', bytes: self::image(7))->photo(self::KEY, 'b.jpg', bytes: self::image(8));
        $this->content->photo(self::KEY, 'gleich.jpg', 'saal', self::image(7));
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        $paths = $this->connection->fetchFirstColumn('SELECT original_path FROM venue_photos ORDER BY room_id NULLS FIRST, position');
        self::assertCount(3, $paths);
        self::assertSame($paths[0], $paths[2]);
        self::assertCount(2, glob($this->paths->original('content/'.self::KEY).'/*') ?: []);

        unlink($this->content->folder(self::KEY).'/photos/a.jpg');
        $this->content->edit(self::KEY, static function (array $data): array {
            $data['photos'] = [['file' => 'b.jpg']];

            return $data;
        });
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertSame(2, $this->rows('venue_photos'));
        self::assertFileExists($this->paths->original($paths[0]));
    }

    /**
     * Three gallery photos (a, b, c), one room photo, one PDF.
     */
    private function venueWithMedia(): void
    {
        $this->content->venue(self::KEY, ['rooms' => [['key' => 'rittersaal', 'translations' => ['de' => ['name' => 'Rittersaal']]]]], 'Schloss Beispiel');
        $this->content->photo(self::KEY, 'a.jpg', bytes: self::image(1), alt: ['de' => 'Aussenansicht']);
        $this->content->photo(self::KEY, 'b.jpg', bytes: self::image(2));
        $this->content->photo(self::KEY, 'c.jpg', bytes: self::image(3));
        $this->content->photo(self::KEY, 'saal.jpg', 'rittersaal', self::image(4));
        $this->content->document(self::KEY, 'preise.pdf');
    }

    /**
     * @return list<array{id: string, position: int, original_path: string, variants: string, room_key: ?string}>
     */
    private function photos(): array
    {
        /** @var list<array{id: string, position: int, original_path: string, variants: string, room_key: ?string}> $photos */
        $photos = $this->connection->fetchAllAssociative('SELECT p.id, p.position, p.original_path, p.variants, r.content_key AS room_key FROM venue_photos p LEFT JOIN rooms r ON r.id = p.room_id ORDER BY p.room_id NULLS FIRST, p.position');

        return $photos;
    }
}
