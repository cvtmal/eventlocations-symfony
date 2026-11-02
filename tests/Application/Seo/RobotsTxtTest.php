<?php

declare(strict_types=1);

namespace App\Tests\Application\Seo;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RobotsTxtTest extends WebTestCase
{
    public function test_the_exact_body(): void
    {
        $client = self::createClient();

        $client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertSame(<<<'TXT'
            User-agent: *
            Disallow: /_search/
            Disallow: /merkliste
            Disallow: /admin
            Disallow: /konto
            Disallow: /hochzeitslocation/*/telefon
            Disallow: /hochzeitslocation/*/anfrage

            Sitemap: http://localhost/sitemap.xml

            TXT, $client->getResponse()->getContent());
        self::assertSame('text/plain; charset=UTF-8', $client->getResponse()->headers->get('Content-Type'));
    }

    public function test_the_sitemap_line_takes_the_scheme_of_the_request(): void
    {
        $client = self::createClient([], ['HTTPS' => 'on']);

        $client->request('GET', '/robots.txt');

        self::assertStringContainsString("\nSitemap: https://localhost/sitemap.xml\n", (string) $client->getResponse()->getContent());
    }
}
