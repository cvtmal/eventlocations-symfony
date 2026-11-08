<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalVenueAccessFactory;
use App\Factory\SubmissionFactory;
use App\Factory\VenueFactory;
use App\Portal\ValueObject\WizardStep;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Portal\PortalSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Walks every route and method with a `{number}` under `/konto`: whatever a later cycle adds there is covered
 * without touching this test (POR-8 "their own locations"). A parameter this test does not know gets a UUID, its
 * 404 fails the refusal tests, and the parameter has to be taught here.
 *
 * Every JSON write carries a real token: `IsCsrfTokenValid` runs before `IsGranted`, so without one the 403 would
 * come from the CSRF listener and prove nothing about the voter. `test_own_location_opens` proves the token passes.
 *
 * @phpstan-type Endpoint array{name: string, method: string, path: string}
 */
final class LocationRoutesAccessTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use PortalSession;

    private const string UUID = '0199f0d0-0000-7000-8000-000000000001';

    /** #44's fifteen and #45's three (the bare wizard URL, a step's GET and POST): an empty walk must not pass. */
    private const int WALKED = 18;

    /** On a published location both send the manager to the edit page. */
    private const array WIZARD_ROUTES = ['portal_location_setup', 'portal_location_setup_step'];

    public function test_another_managers_location_is_refused(): void
    {
        $venue = DetailVenues::minimal();
        PortalVenueAccessFactory::new()->active()->create(['venue' => $venue]);
        $this->logInAsManager();

        $this->assertEveryRouteIsRefused($venue);
    }

    public function test_pending_access_is_no_access(): void
    {
        $venue = DetailVenues::minimal();
        PortalVenueAccessFactory::createOne(['user' => $this->logInAsManager(), 'venue' => $venue]);

        $this->assertEveryRouteIsRefused($venue);
    }

    public function test_own_location_opens(): void
    {
        $venue = DetailVenues::minimal();
        PortalVenueAccessFactory::new()->active()->create(['user' => $this->logInAsManager(), 'venue' => $venue]);
        $token = $this->csrfToken();

        $opened = 0;
        foreach ($this->endpoints($venue) as $endpoint) {
            $label = $endpoint['method'].' '.$endpoint['path'];

            if ('GET' === $endpoint['method']) {
                $this->send($endpoint, $token);
                // A page of one row (a PDF's titles, F11) gets the sample id, which names no row: past the voter that is a 404.
                $expected = match (true) {
                    \in_array($endpoint['name'], self::WIZARD_ROUTES, true) => Response::HTTP_FOUND,
                    str_contains($endpoint['path'], self::UUID) => Response::HTTP_NOT_FOUND,
                    default => Response::HTTP_OK,
                };
                self::assertSame($expected, $this->client->getResponse()->getStatusCode(), $label);
                ++$opened;
            } elseif (self::isJsonEndpoint($endpoint)) {
                $this->send($endpoint, $token);
                self::assertNotSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode(), $label);
            }
        }

        self::assertGreaterThanOrEqual(3, $opened);
    }

    /**
     * The walks above run on a published location, which the wizard only redirects. This one is a draft, so a
     * refusal here is the voter's and not that redirect.
     */
    public function test_a_manager_without_access_cannot_touch_a_draft(): void
    {
        $venue = VenueFactory::createOne(['status' => VenueStatus::Unpublished]);
        SubmissionFactory::createOne(['venue' => $venue]);
        PortalVenueAccessFactory::new()->active()->create(['venue' => $venue]);
        $this->logInAsManager();
        $rows = $this->draftRows($venue);

        foreach (['texte', 'fotos', 'pruefen'] as $step) {
            foreach (['GET', 'POST'] as $method) {
                $this->client->request($method, '/konto/locations/'.$venue->number().'/einrichten/'.$step);

                self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $method.' '.$step);
            }
        }

        self::assertSame($rows, $this->draftRows($venue));
    }

    private function assertEveryRouteIsRefused(Venue $venue): void
    {
        $token = $this->csrfToken();
        $auditRows = $this->auditRows();

        $endpoints = $this->endpoints($venue);
        foreach ($endpoints as $endpoint) {
            $this->send($endpoint, $token);

            $label = $endpoint['method'].' '.$endpoint['path'];
            self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode(), $label);
            if (self::isJsonEndpoint($endpoint)) {
                self::assertSame('application/json', $this->client->getResponse()->headers->get('Content-Type'), $label);
            }
        }

        self::assertGreaterThanOrEqual(self::WALKED, \count($endpoints));
        self::assertSame($auditRows, $this->auditRows());
        self::assertNull($this->connection->fetchOne('SELECT cms_edited_at FROM venues WHERE id = ?', [$venue->id()]));
    }

    /**
     * One entry per route and method.
     *
     * @return list<Endpoint>
     */
    private function endpoints(Venue $venue): array
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $endpoints = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/konto/') || !str_contains($route->getPath(), '{number}')) {
                continue;
            }

            $path = (string) preg_replace_callback(
                '/\{([^}]+)\}/',
                static fn (array $match): string => match (true) {
                    'number' === $match[1] => (string) $venue->number(),
                    WizardStep::REQUIREMENT === $route->getRequirement($match[1]) => 'texte',
                    default => self::UUID,
                },
                $route->getPath(),
            );
            foreach ($route->getMethods() ?: ['GET', 'POST'] as $method) {
                $endpoints[] = ['name' => $name, 'method' => $method, 'path' => $path];
            }
        }

        return $endpoints;
    }

    /**
     * @param Endpoint $endpoint
     */
    private function send(array $endpoint, string $token): void
    {
        if (!self::isJsonEndpoint($endpoint)) {
            $this->client->request($endpoint['method'], $endpoint['path']);

            return;
        }

        $this->client->request($endpoint['method'], $endpoint['path'], server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: '{}');
    }

    /**
     * @param Endpoint $endpoint
     */
    private static function isJsonEndpoint(array $endpoint): bool
    {
        return str_starts_with($endpoint['path'], '/konto/api/');
    }

    /**
     * A real page's token, as the island reads it.
     */
    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/konto/profil');

        return (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
    }

    /**
     * @return array{venue: array<string, mixed>|false, submission: array<string, mixed>|false}
     */
    private function draftRows(Venue $venue): array
    {
        return [
            'venue' => $this->connection->fetchAssociative('SELECT * FROM venues WHERE id = ?', [$venue->id()]),
            'submission' => $this->connection->fetchAssociative('SELECT * FROM portal_submissions WHERE venue_id = ?', [$venue->id()]),
        ];
    }

    private function auditRows(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM admin_audit_log');
    }
}
