<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Filesystem\Filesystem;

/**
 * S10, §19: an import reaches visitors at once, because its purges invalidate the whole store
 * (the metadata; HttpCacheClearer::invalidate() keeps the bodies).
 */
final class ImportClearsHttpCacheTest extends KernelTestCase
{
    use RunsContentImport;

    public function test_an_import_invalidates_the_store(): void
    {
        $shareDirectory = self::getContainer()->getParameter('kernel.share_dir');
        self::assertIsString($shareDirectory);
        $store = $shareDirectory.'/http_cache';
        (new Filesystem())->dumpFile($store.'/md/marker', 'cached page');
        $this->content->venue('schloss-beispiel');

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertDirectoryDoesNotExist($store.'/md');
        $number = (int) $this->connection->fetchOne("SELECT number FROM venues WHERE content_key = 'schloss-beispiel'");
        self::assertSame([$number], $this->purger()->venues);
        self::assertSame(1, $this->purger()->listings);
    }
}
