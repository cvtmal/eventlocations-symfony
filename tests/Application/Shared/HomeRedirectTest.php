<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * S5: `/` answers 302 to the listing until a home page exists; the query string is kept.
 */
final class HomeRedirectTest extends WebTestCase
{
    public function test_root_redirects_to_the_listing(): void
    {
        $client = self::createClient();

        foreach (['/' => 'http://localhost/hochzeitslocations', '/?canton=zh' => 'http://localhost/hochzeitslocations?canton=zh'] as $uri => $location) {
            $client->request('GET', $uri);
            $response = $client->getResponse();

            self::assertResponseStatusCodeSame(302);
            self::assertSame($location, $response->headers->get('Location'));
            self::assertStringNotContainsString('_canonical_route', (string) $response->headers->get('Location'));
            self::assertStringNotContainsString('_locale', (string) $response->headers->get('Location'));
            self::assertFalse($response->headers->has('Set-Cookie'));
        }
    }
}
