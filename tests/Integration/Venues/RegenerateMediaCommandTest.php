<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\VenueDocumentFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Media\MediaPaths;
use App\Media\ValueObject\ImageFormat;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Media\MediaFiles;
use App\Tests\Support\Media\TestImages;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * app:media:regenerate against app_test in a rolled-back transaction, with originals drawn
 * at runtime under the test MEDIA_ROOT and output in the test public dir.
 */
final class RegenerateMediaCommandTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private Connection $connection;
    private EntityManagerInterface $em;
    private MediaPaths $paths;
    private CommandTester $tester;
    private string $folder;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $paths = $container->get(MediaPaths::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(MediaPaths::class, $paths);
        $this->em = $em;
        $this->paths = $paths;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->tester = new CommandTester((new Application($kernel))->find('app:media:regenerate'));
        $this->folder = 'test-regenerate-'.uniqid();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        MediaFiles::removeDirectory($this->paths->original($this->folder));
        MediaFiles::removeDirectory($this->paths->publicDirectory());
        parent::tearDown();
    }

    public function test_regenerates_one_venue(): void
    {
        $venue = VenueFactory::createOne();
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'originalPath' => $this->original('saal.jpg', 1000, 600)]);
        $document = VenueDocumentFactory::createOne(['venue' => $venue, 'path' => $this->pdf('preise.pdf')]);
        $number = $venue->number();
        $photoId = $photo->id();
        $documentId = $document->id();

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--venue' => (string) $number]));

        self::assertStringContainsString('Venues: 1. Photos: 1 regenerated. Documents: 1 published.', $this->tester->getDisplay());
        $files = array_map('basename', glob($this->paths->photoDirectory($number).'/*') ?: []);
        self::assertCount(10, $files);
        foreach ([360, 640, 960] as $width) {
            foreach (ImageFormat::cases() as $format) {
                self::assertFileExists($this->paths->variantFile($number, $photoId, $width, $format));
            }
        }
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($this->paths->documentFile($number, $documentId)));

        $row = $this->connection->fetchAssociative('SELECT width, height, dominant_color, variants FROM venue_photos WHERE id = ?', [$photoId]);
        self::assertIsArray($row);
        self::assertSame([1000, 600], [$row['width'], $row['height']]);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', (string) $row['dominant_color']);
        self::assertSame(['widths' => [360, 640, 960], 'formats' => ['avif', 'webp', 'jpg']], json_decode((string) $row['variants'], true));
    }

    public function test_a_missing_original_is_reported_and_skipped(): void
    {
        $venue = VenueFactory::createOne();
        $missing = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1, 'originalPath' => $this->folder.'/missing.jpg']);
        $present = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2, 'originalPath' => $this->original('garten.jpg', 800, 500)]);
        $number = $venue->number();
        $missingId = $missing->id();
        $presentId = $present->id();

        self::assertSame(Command::FAILURE, $this->tester->execute(['--venue' => (string) $number]));

        $output = $this->tester->getDisplay();
        self::assertStringContainsString('Photos: 1 regenerated', $output);
        self::assertStringContainsString($number.'/'.$missingId.': ', $output);
        self::assertFileExists($this->paths->variantFile($number, $presentId, 640, ImageFormat::Avif));
        self::assertSame(800, (int) $this->connection->fetchOne('SELECT width FROM venue_photos WHERE id = ?', [$presentId]));
        self::assertSame(1600, (int) $this->connection->fetchOne('SELECT width FROM venue_photos WHERE id = ?', [$missingId]));
    }

    public function test_an_unknown_venue_number_fails(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute(['--venue' => '999999']));

        self::assertStringContainsString('No venue with number 999999.', $this->tester->getDisplay());
    }

    public function test_without_a_number_every_venue_is_processed(): void
    {
        $venues = $this->connection->fetchOne('SELECT count(*) FROM venues');
        $venue = VenueFactory::createOne();
        VenuePhotoFactory::createOne(['venue' => $venue, 'originalPath' => $this->original('a.jpg', 400, 300)]);
        VenuePhotoFactory::createOne(['venue' => VenueFactory::createOne(), 'originalPath' => $this->original('b.jpg', 500, 300)]);

        $status = $this->tester->execute([]);

        self::assertStringContainsString(\sprintf('Venues: %d. Photos: ', (int) $venues + 2), $this->tester->getDisplay());
        self::assertFileExists($this->paths->variantFile($this->number($venue), $this->photoIdOf($venue), 360, ImageFormat::Jpeg));
        if (0 === (int) $venues) {
            self::assertSame(Command::SUCCESS, $status);
            self::assertStringContainsString('Photos: 2 regenerated.', $this->tester->getDisplay());
        }
    }

    private function original(string $name, int $width, int $height): string
    {
        \assert($width > 0 && $height > 0);
        TestImages::write($this->paths->original($this->folder), $name, TestImages::jpeg($width, $height));

        return $this->folder.'/'.$name;
    }

    private function pdf(string $name): string
    {
        TestImages::write($this->paths->original($this->folder), $name, "%PDF-1.4\n%%EOF\n");

        return $this->folder.'/'.$name;
    }

    private function number(Venue $venue): int
    {
        return (int) $this->connection->fetchOne('SELECT number FROM venues WHERE id = ?', [$venue->id()]);
    }

    private function photoIdOf(Venue $venue): string
    {
        return (string) $this->connection->fetchOne('SELECT id FROM venue_photos WHERE venue_id = ?', [$venue->id()]);
    }
}
