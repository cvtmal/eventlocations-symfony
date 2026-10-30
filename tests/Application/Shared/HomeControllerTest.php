<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function test_home_renders_the_placeholder(): void
    {
        $client = self::createClient();

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Hochzeitslocation');
    }
}
