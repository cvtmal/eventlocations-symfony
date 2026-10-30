<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class StyleguideTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_the_island_page_loads_the_module_and_its_chunk(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin/_styleguide');

        self::assertResponseIsSuccessful();
        $island = $crawler->filter('[data-island="hello"]');
        self::assertCount(1, $island);
        $props = json_decode((string) $island->attr('data-props'), true);
        self::assertIsArray($props);
        self::assertSame('Hallo aus der Vue-Insel', $props['greeting']);
        self::assertCount(1, $crawler->filter('script[type="module"][src^="/admin-build/"]'));
        self::assertGreaterThan(0, $crawler->filter('link[rel="modulepreload"][href*="HelloIsland"]')->count());
    }

    public function test_a_page_without_an_island_loads_none(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin');

        self::assertCount(0, $crawler->filter('script[src^="/admin-build/"]'));
        self::assertCount(0, $crawler->filter('link[rel="modulepreload"]'));
    }
}
