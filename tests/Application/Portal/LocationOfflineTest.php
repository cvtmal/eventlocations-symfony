<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalVenueAccessFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Content\ContentFolder;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Tests\Support\Portal\PortalSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Zenstruck\Foundry\Test\Factories;

final class LocationOfflineTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use PortalSession {
        tearDown as private endPortalSession;
    }

    private ?ContentFolder $content = null;

    protected function tearDown(): void
    {
        $this->content?->removeAll();
        $this->endPortalSession();
    }

    public function test_a_manager_takes_a_location_offline(): void
    {
        $venue = $this->managedVenue();
        $crawler = $this->client->request('GET', $this->url($venue));

        $this->client->submit($crawler->filter('#location-offline form[name="offline"]')->form());

        self::assertResponseRedirects($this->url($venue));
        $crawler = $this->client->followRedirect();
        self::assertSame('«Gartenhaus Minimal» ist offline.', $crawler->filter('.toast')->text());
        self::assertSelectorNotExists('#location-offline');
        self::assertSame('unpublished', $this->venueStatus($venue->number()));
        self::assertNotNull($this->connection->fetchOne('SELECT cms_edited_at FROM venues WHERE id = ?', [$venue->id()]));
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(
            ['portal_user'],
            $this->connection->fetchFirstColumn("SELECT actor_type FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $venue->number()]),
        );
    }

    public function test_the_header_says_that_going_back_online_takes_a_review(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->managedVenue()));

        self::assertStringContainsString('prüft unser Team sie zuerst', $crawler->filter('#location-offline')->text());
    }

    public function test_the_photos_tab_offers_it_too(): void
    {
        $venue = $this->managedVenue();

        $crawler = $this->client->request('GET', $this->url($venue).'/fotos');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#location-offline form[name="offline"]'));
        self::assertSame('Fotos', $crawler->filter('.tab--active')->text());
    }

    public function test_a_location_already_offline_dispatches_nothing(): void
    {
        $venue = $this->managedVenue();
        $crawler = $this->client->request('GET', $this->url($venue));
        // Staff took it offline meanwhile.
        $this->connection->executeStatement("UPDATE venues SET status = 'unpublished' WHERE id = ?", [$venue->id()]);

        $this->client->submit($crawler->filter('#location-offline form[name="offline"]')->form());

        self::assertSame('«Gartenhaus Minimal» war schon offline.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame(0, $this->auditRows($venue->number()));
        self::assertSame([], $this->purger()->venues);
        self::assertNull($this->connection->fetchOne('SELECT cms_edited_at FROM venues WHERE id = ?', [$venue->id()]));
    }

    public function test_a_missing_csrf_token_changes_nothing(): void
    {
        $venue = $this->managedVenue();

        $this->client->request('POST', $this->url($venue).'/offline');

        self::assertResponseRedirects($this->url($venue));
        self::assertStringContainsString('Das hat nicht geklappt', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame('published', $this->venueStatus($venue->number()));
        self::assertSame(0, $this->auditRows($venue->number()));
    }

    public function test_the_import_does_not_bring_an_offline_location_back(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $this->content = new ContentFolder($kernel->getProjectDir().'/var/test-content-'.uniqid());
        $this->content->venue('gasthaus-offline');
        $import = new CommandTester((new Application($kernel))->find('app:venues:import'));
        $import->execute(['path' => $this->content->path()]);
        $number = (int) $this->connection->fetchOne("SELECT number FROM venues WHERE content_key = 'gasthaus-offline'");
        self::assertSame('published', $this->venueStatus($number), $import->getDisplay());
        $venue = $this->portalEntityManager()->getRepository(Venue::class)->findOneBy(['number' => $number]);
        self::assertInstanceOf(Venue::class, $venue);
        PortalVenueAccessFactory::new()->active()->create(['user' => $this->logInAsManager(), 'venue' => $venue]);

        $crawler = $this->client->request('GET', '/konto/locations/'.$number);
        $this->client->submit($crawler->filter('#location-offline form[name="offline"]')->form());
        self::assertSame('unpublished', $this->venueStatus($number));

        $this->content->change('gasthaus-offline', ['capacity' => ['max' => 200]]);
        $import->execute(['path' => $this->content->path(), '--force' => true]);

        self::assertStringContainsString('cms_edited  gasthaus-offline', $import->getDisplay());
        self::assertSame('unpublished', $this->venueStatus($number));
    }

    public function test_no_konto_route_publishes(): void
    {
        $venue = $this->managedVenue();
        $this->connection->executeStatement("UPDATE venues SET status = 'unpublished' WHERE id = ?", [$venue->id()]);
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        foreach ($router->getRouteCollection() as $name => $route) {
            if (str_starts_with($route->getPath(), '/konto')) {
                self::assertStringNotContainsString('publi', $name.$route->getPath(), $name);
                self::assertStringNotContainsString('online', $name.$route->getPath(), $name);
            }
        }

        $crawler = $this->client->request('GET', $this->url($venue));
        self::assertSelectorNotExists('#location-offline');
        self::assertCount(0, $crawler->filter('form[name="publish"]'));
        $this->client->request('POST', $this->url($venue).'/publizieren');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('unpublished', $this->venueStatus($venue->number()));
    }

    private function managedVenue(): Venue
    {
        $venue = DetailVenues::minimal();
        PortalVenueAccessFactory::new()->active()->create(['user' => $this->logInAsManager(), 'venue' => $venue]);

        return $venue;
    }

    private function url(Venue $venue): string
    {
        return '/konto/locations/'.$venue->number();
    }

    private function venueStatus(int $number): string
    {
        return (string) $this->connection->fetchOne('SELECT status FROM venues WHERE number = ?', [$number]);
    }

    private function auditRows(int $number): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $number]);
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }
}
