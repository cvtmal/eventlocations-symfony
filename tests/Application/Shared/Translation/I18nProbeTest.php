<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared\Translation;

use App\Tests\Fixtures\I18n\I18nProbeKernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A localized route renders German strings from two catalogue domains, Swiss formats and
 * the hreflang alternates of a German-only site (Done-when 1, SEO-6).
 */
final class I18nProbeTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return I18nProbeKernel::class;
    }

    public function test_german_page_uses_two_domains_and_swiss_formats(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/i18n-probe');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Language', 'de');
        self::assertSame('Willkommen auf der Probeseite', $crawler->filter('h1')->text());
        self::assertSame('3 Gäste', $crawler->filter('.extra')->text());
        self::assertSame('50 - 450', $crawler->filter('.capacity')->text());

        self::assertSame([
            ['de-CH', 'http://localhost/i18n-probe'],
            ['x-default', 'http://localhost/i18n-probe'],
        ], $crawler->filter('link[rel=alternate]')->each(
            static fn (Crawler $link): array => [$link->attr('hreflang'), $link->attr('href')],
        ));
    }
}
