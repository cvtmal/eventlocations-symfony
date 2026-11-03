<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\EventlokaleCache;
use App\Crm\EventlokaleCsv;
use App\Tests\Support\Crm\EventlokalePayloads;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The test transport refuses every eventlokale URL, so the crawl itself is covered by
 * EventlokaleCrawlTest; here the command runs from a seeded cache.
 */
final class CrawlEventlokaleCommandTest extends KernelTestCase
{
    private string $root;
    private CommandTester $command;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->root = $kernel->getProjectDir().'/var/test-content-eventlokale-'.uniqid();
        $this->command = new CommandTester((new Application($kernel))->find('app:crm:crawl-eventlokale'));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->root.'/eventlokale/venues/*') ?: []);
        array_map('unlink', glob($this->root.'/eventlokale/*.json') ?: []);
        array_map('unlink', glob($this->root.'/owner/*') ?: []);
        @rmdir($this->root.'/eventlokale/venues');
        @rmdir($this->root.'/eventlokale');
        @rmdir($this->root.'/owner');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_from_cache_writes_the_csv_without_a_request(): void
    {
        $cache = new EventlokaleCache($this->root);
        $cache->writeTaxonomies(EventlokalePayloads::taxonomies());
        $cache->writeIndex([EventlokalePayloads::listItem(1), EventlokalePayloads::listItem(2)], 2, new \DateTimeImmutable());
        $cache->writeDetail(1, EventlokalePayloads::detail(1));

        $status = $this->command->execute(['path' => $this->root, '--from-cache' => true]);

        self::assertSame(Command::SUCCESS, $status, $this->command->getDisplay());
        $lines = file($this->root.'/'.EventlokaleCsv::FILE, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        self::assertCount(1 + 2, $lines);
        self::assertStringContainsString('CSV: '.$this->root.'/'.EventlokaleCsv::FILE.' (2 rows)', $this->command->getDisplay());
        self::assertStringContainsString('Empty in every row: ', $this->command->getDisplay());
    }

    public function test_from_cache_fails_without_a_cached_list(): void
    {
        $status = $this->command->execute(['path' => $this->root, '--from-cache' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No venue list', $this->command->getDisplay());
        self::assertFileDoesNotExist($this->root.'/'.EventlokaleCsv::FILE);
    }

    public function test_a_crawl_reaches_eventlokale_through_the_scoped_client(): void
    {
        try {
            $this->command->execute(['path' => $this->root, '--list-only' => true]);
            self::fail('The test transport should have refused the request.');
        } catch (\LogicException $exception) {
            while (null !== $exception->getPrevious()) {
                $exception = $exception->getPrevious();
            }
            self::assertStringContainsString('GET https://www.eventlokale.ch/api/guest/location/all-taxonomies', $exception->getMessage());
        }
    }

    public function test_negative_and_contradicting_options_are_refused(): void
    {
        self::assertSame(Command::FAILURE, $this->command->execute(['path' => $this->root, '--delay-ms' => '-1']));
        self::assertSame(Command::FAILURE, $this->command->execute(['path' => $this->root, '--from-cache' => true, '--refresh' => true]));
        self::assertDirectoryDoesNotExist($this->root);
    }
}
