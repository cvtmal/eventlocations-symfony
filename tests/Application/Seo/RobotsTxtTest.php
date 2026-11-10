<?php

declare(strict_types=1);

namespace App\Tests\Application\Seo;

use App\Seo\Controller\RobotsController;
use App\Shared\Security\ReviewMode;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\Container;

final class RobotsTxtTest extends WebTestCase
{
    /** Pre-launch (`seo.robots_block_google: true`): Googlebot is kept off the site, everyone else is not. */
    public function test_the_exact_body(): void
    {
        $client = self::createClient();

        $client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertSame(<<<'TXT'
            User-agent: Googlebot
            Disallow: /

            User-agent: *
            Disallow: /_search/
            Disallow: /merkliste
            Disallow: /admin
            Disallow: /konto
            Disallow: /eventlokal/*/telefon
            Disallow: /eventlokal/*/anfrage
            Disallow: /eventlokal/*/google-fotos
            Disallow: /_stats

            Sitemap: http://localhost/sitemap.xml

            TXT, $client->getResponse()->getContent());
        self::assertSame('text/plain; charset=UTF-8', $client->getResponse()->headers->get('Content-Type'));
    }

    public function test_without_the_switch_there_is_no_googlebot_group(): void
    {
        self::bootKernel();
        $services = new Container();
        $services->set('router', self::getContainer()->get('router'));
        $controller = new RobotsController(false, new ReviewMode(false));
        $controller->setContainer($services);

        $body = (string) $controller()->getContent();

        self::assertStringStartsWith("User-agent: *\nDisallow: /_search/\n", $body);
        self::assertStringNotContainsString('Googlebot', $body);
    }

    public function test_review_mode_disallows_everything(): void
    {
        self::bootKernel();
        $controller = new RobotsController(false, new ReviewMode(true));

        self::assertSame("User-agent: *\nDisallow: /\n", (string) $controller()->getContent());
    }

    public function test_the_sitemap_line_takes_the_scheme_of_the_request(): void
    {
        $client = self::createClient([], ['HTTPS' => 'on']);

        $client->request('GET', '/robots.txt');

        self::assertStringContainsString("\nSitemap: https://localhost/sitemap.xml\n", (string) $client->getResponse()->getContent());
    }
}
