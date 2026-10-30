<?php

declare(strict_types=1);

namespace App\Tests\Application\Venues;

use App\Media\MediaPaths;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Content\ContentFolder;
use App\Tests\Support\Media\MediaFiles;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The content import's rename through #8's route: the old URL answers 301 to the new one
 * (#9 Done when: "rename (301 works)"). The client shares the test's connection (no reboot),
 * so what the import writes is visible to the request and rolled back afterwards.
 */
final class ImportedRenameRedirectTest extends WebTestCase
{
    use ImportsTownDataset;

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $em;
    private MediaPaths $paths;
    private ContentFolder $content;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $kernel = $this->client->getKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $paths = self::getContainer()->get(MediaPaths::class);
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
        $this->connection->rollBack();
        $this->content->removeAll();
        MediaFiles::removeDirectory($this->paths->original('content'));
        MediaFiles::removeDirectory($this->paths->publicDirectory());
        parent::tearDown();
    }

    public function test_the_old_slug_of_a_renamed_venue_redirects_to_the_new_one(): void
    {
        $this->content->venue('schloss-beispiel', name: 'Schloss Beispiel');
        $this->import();
        $this->client->request('GET', '/hochzeitslocation/schloss-beispiel');
        self::assertResponseIsSuccessful();

        $this->content->change('schloss-beispiel', ['name' => 'Schloss Neuheim']);
        $this->import();

        $this->client->request('GET', '/hochzeitslocation/schloss-beispiel');
        self::assertResponseRedirects('/hochzeitslocation/schloss-neuheim', 301);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Schloss Neuheim');
    }

    public function test_a_rename_back_serves_the_old_slug_again_and_redirects_the_other(): void
    {
        $this->content->venue('schloss-beispiel', name: 'Schloss Beispiel');
        $this->import();
        $this->content->change('schloss-beispiel', ['name' => 'Schloss Neuheim']);
        $this->import();
        $this->content->change('schloss-beispiel', ['name' => 'Schloss Beispiel']);
        $this->import();

        $this->client->request('GET', '/hochzeitslocation/schloss-beispiel');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/hochzeitslocation/schloss-neuheim');
        self::assertResponseRedirects('/hochzeitslocation/schloss-beispiel', 301);
    }

    private function import(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester->execute(['path' => $this->content->path()]), $this->tester->getDisplay());
    }
}
