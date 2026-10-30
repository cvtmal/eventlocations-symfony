<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Media\MediaPaths;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Content\ContentFolder;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Tests\Support\Media\MediaFiles;
use App\Tests\Support\Media\TestImages;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:venues:import against app_test in a rolled-back transaction (each venue's own
 * transaction nests as a savepoint), with fictional content folders built at runtime under
 * var/ and media in the test media dirs, all removed in tearDown(). Use in a KernelTestCase.
 */
trait RunsContentImport
{
    use ImportsTownDataset;

    private Connection $connection;
    private EntityManagerInterface $em;
    private MediaPaths $paths;
    private ContentFolder $content;
    private CommandTester $tester;

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
        $this->content = new ContentFolder($kernel->getProjectDir().'/var/test-content-'.uniqid());
        $this->tester = new CommandTester((new Application($kernel))->find('app:venues:import'));
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        while ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->content->removeAll();
        MediaFiles::removeDirectory($this->paths->original('content'));
        MediaFiles::removeDirectory($this->paths->publicDirectory());
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $options e.g. ['--dry-run' => true]
     */
    private function import(array $options = []): int
    {
        return $this->tester->execute(['path' => $this->content->path(), ...$options]);
    }

    private function display(): string
    {
        return $this->tester->getDisplay();
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }

    /**
     * @param list<mixed>|array<string, mixed> $params
     */
    private function rows(string $sql, array $params = []): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM '.$sql, $params);
    }

    /**
     * @return array<string, mixed>|false
     */
    private function venueRow(string $key): array|false
    {
        return $this->connection->fetchAssociative('SELECT * FROM venues WHERE content_key = ?', [$key]);
    }

    private function venueId(string $key): string
    {
        $id = $this->connection->fetchOne('SELECT id FROM venues WHERE content_key = ?', [$key]);
        self::assertIsString($id, $key.' was not imported');

        return $id;
    }

    private function venueNumber(string $key): int
    {
        return (int) $this->connection->fetchOne('SELECT number FROM venues WHERE content_key = ?', [$key]);
    }

    /**
     * A small JPEG of its own colour, so photos of one venue never share a hash.
     */
    /**
     * @param int<0, max> $n
     */
    private static function image(int $n): string
    {
        return TestImages::uniformJpeg(400, 260, (37 * $n) % 256, (91 * $n) % 256, (53 * $n + 17) % 256);
    }

    /**
     * Sets every media file's mtime to 2001-09-09, so a rewrite within the same second shows.
     */
    private function ageFiles(): void
    {
        foreach (array_keys($this->fileTimes()) as $file) {
            touch($file, 1_000_000_000);
        }
        clearstatcache();
    }

    /**
     * Modification times of every file under the test public dir and MEDIA_ROOT/content.
     *
     * @return array<string, int|false>
     */
    private function fileTimes(): array
    {
        clearstatcache();
        $times = [];
        foreach ([$this->paths->publicDirectory(), $this->paths->original('content')] as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                \assert($file instanceof \SplFileInfo);
                $times[$file->getPathname()] = $file->getMTime();
            }
        }
        ksort($times);

        return $times;
    }
}
