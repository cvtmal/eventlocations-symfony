<?php

declare(strict_types=1);

namespace App\Tests\Application\Venues;

use App\Tests\Support\Http\PublicRouteFixtures;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Command\RecordSiteImport;
use App\Venues\ValueObject\CandidateOrigin;
use App\Venues\ValueObject\ImageCandidate;
use App\Venues\ValueObject\SourceType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * G3, D16: what the importer finds is private. A published venue has a source record with candidates whose every
 * value carries a marker; every public route (all that are not behind the back office or /konto firewalls) is
 * walked with real parameters and none of them may answer with a marker, in the body or in a header. A new
 * public route needs parameters here, like in the session walker.
 */
final class SiteImportIsPrivateTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use PublicRouteFixtures;

    private const array MARKERS = ['zz-image-marker-7f3a', 'zz-page-marker-2c81', 'zz-alt-marker-55d0', 'zz-fact-marker-91c2', 'zz-context-marker-0b6e', 'zz-source-marker-d41f'];

    /** Not part of a production build. */
    private const array DEV_ONLY = ['_styleguide'];

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        self::mockTime(self::FROZEN_NOW);
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_no_public_route_answers_with_anything_the_importer_found(): void
    {
        $data = $this->seedPublicRoutes();
        $this->seedImport($data['number']);

        $parameters = [
            'venue_show' => [['slug' => DetailVenues::FULL_SLUG], ['slug' => self::HISTORY_SLUG]],
            'venue_markdown' => [['slug' => DetailVenues::FULL_SLUG], ['slug' => self::HISTORY_SLUG]],
            'venue_phone' => [['slug' => DetailVenues::FULL_SLUG]],
            'inquiry_submit' => [['slug' => DetailVenues::FULL_SLUG]],
            'listing_landing' => [['slug' => 'zuerich'], ['slug' => $data['building']]],
            'listing_town' => [['canton' => 'zuerich', 'town' => $data['town']]],
            'sitemap_venues' => [['n' => 1]],
            'merkliste_toggle' => [['number' => $data['number']]],
            'venue_preview' => [['token' => $data['preview']]],
        ];
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $walked = 0;

        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $canonical = $route->getDefault('_canonical_route') ?? $name;
            \assert(\is_string($canonical));
            if ($this->isBehindAFirewall($route) || \in_array($canonical, self::DEV_ONLY, true)) {
                continue;
            }
            $required = array_diff($route->compile()->getPathVariables(), array_keys($route->getDefaults()));
            if ([] !== $required && !isset($parameters[$canonical])) {
                self::fail("Add {$canonical} to the parameters of the walker.");
            }

            foreach ($parameters[$canonical] ?? [[]] as $sample) {
                $url = $router->generate($name, $sample);
                $this->client->request('GET', $url, [], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
                $response = $this->client->getResponse();
                $everything = strtolower($response->headers->__toString().$response->getContent());
                foreach (self::MARKERS as $marker) {
                    self::assertStringNotContainsString($marker, $everything, "GET {$url} must not show an imported value.");
                }
                ++$walked;
            }
        }

        self::assertGreaterThan(10, $walked);
    }

    public function test_no_route_is_named_after_the_import(): void
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        foreach ($router->getRouteCollection()->all() as $name => $route) {
            self::assertDoesNotMatchRegularExpression('/source[-_]?record|photo[-_]?candidate|site[-_]?import|candidate/i', $name.' '.$route->getPath().' '.($route->getDefault('_controller') ?? ''), "Route {$name} must not serve the importer's data.");
        }
    }

    private function seedImport(int $venueNumber): void
    {
        $venueId = $this->connection->fetchOne('SELECT id FROM venues WHERE number = ?', [$venueNumber]);
        self::assertIsString($venueId);
        $bus = self::getContainer()->get(MessageBusInterface::class);
        \assert($bus instanceof MessageBusInterface);

        $bus->dispatch(new RecordSiteImport(
            'https://zz-source-marker-d41f.example/',
            $venueId,
            SourceType::OfficialWebsite,
            ['name' => [['value' => 'ZZ-Fact-Marker-91c2', 'confidence' => 0.9, 'source_url' => 'https://zz-source-marker-d41f.example/', 'via' => 'json_ld']]],
            ['final_url' => 'https://zz-source-marker-d41f.example/', 'pages' => [], 'refused' => []],
            [new ImageCandidate('https://zz-image-marker-7f3a.example/saal.jpg', 'https://zz-page-marker-2c81.example/', CandidateOrigin::Img, 'ZZ-Alt-Marker-55d0', 'ZZ-Context-Marker-0b6e', 1200, 800)],
            new \DateTimeImmutable('2026-10-30 10:00:00'),
        ));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM photo_candidates'));
    }

    private function isBehindAFirewall(Route $route): bool
    {
        return 1 === preg_match('#^/(admin|konto)(/|$)#', $route->getPath());
    }
}
