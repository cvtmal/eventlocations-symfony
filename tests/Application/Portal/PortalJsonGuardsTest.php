<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalVenueAccessFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Portal\PortalSession;
use App\Tests\Support\Venues\DetailVenues;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * What the firewall answers: JSON under `/konto/api/`, however the path is spelled, and pages as before.
 */
final class PortalJsonGuardsTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use PortalSession;

    /**
     * @return iterable<string, array{string}>
     */
    public static function apiPaths(): iterable
    {
        yield 'plain' => ['/konto/api/towns'];
        yield 'percent-encoded' => ['/konto/%61pi/towns'];
    }

    #[DataProvider('apiPaths')]
    public function test_an_anonymous_json_request_is_a_json_401(string $path): void
    {
        $this->client->request('GET', $path);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function test_an_anonymous_write_is_a_json_401_even_with_a_token(): void
    {
        $venue = DetailVenues::minimal();

        $this->client->request('POST', '/konto/api/locations/'.$venue->number().'/photos', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'any']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function test_an_anonymous_page_goes_to_the_login(): void
    {
        $this->client->request('GET', '/konto');

        self::assertResponseRedirects('/konto/anmelden');
    }

    public function test_a_manager_on_another_managers_page_gets_the_html_403(): void
    {
        $venue = DetailVenues::minimal();
        PortalVenueAccessFactory::new()->active()->create(['venue' => $venue]);
        $this->logInAsManager();

        $this->client->request('GET', '/konto/locations/'.$venue->number());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertStringStartsWith('text/html', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }
}
