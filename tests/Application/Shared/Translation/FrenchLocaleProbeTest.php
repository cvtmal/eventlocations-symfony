<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared\Translation;

use App\Tests\Fixtures\I18n\FrenchProbeKernel;
use App\Tests\Support\Translation\CatalogueParity;
use App\Tests\Support\Translation\KernelLocales;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * Enabling French needs configuration, a catalogue and a route path only (Done-when 3).
 * The French kernel exists only in tests; nothing under src/ or config/ is changed for it.
 */
final class FrenchLocaleProbeTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return FrenchProbeKernel::class;
    }

    public function test_enabled_locales_include_french(): void
    {
        self::assertSame(['de', 'fr'], KernelLocales::enabled(self::getContainer()));
    }

    public function test_french_path_serves_french(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/fr/i18n-probe');

        self::assertResponseIsSuccessful();
        self::assertSame('fr', $client->getRequest()->getLocale());
        self::assertResponseHeaderSame('Content-Language', 'fr');
        self::assertSame("Bienvenue sur la page d'essai", $crawler->filter('h1')->text());
        // probe_extra has no French catalogue: the German string is the fallback.
        self::assertSame('3 Gäste', $crawler->filter('.extra')->text());
        self::assertSame("1\u{202F}234\u{202F}567,5", $crawler->filter('.number')->text(normalizeWhitespace: false));

        self::assertSame([
            ['de-CH', 'http://localhost/i18n-probe'],
            ['fr-CH', 'http://localhost/fr/i18n-probe'],
            ['x-default', 'http://localhost/i18n-probe'],
        ], $crawler->filter('link[rel=alternate]')->each(
            static fn (Crawler $link): array => [$link->attr('hreflang'), $link->attr('href')],
        ));
    }

    public function test_locale_comes_from_the_url_not_the_header(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/i18n-probe', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);

        self::assertResponseIsSuccessful();
        self::assertSame('Willkommen auf der Probeseite', $crawler->filter('h1')->text());
        self::assertStringNotContainsStringIgnoringCase(
            'Accept-Language',
            implode(',', $client->getResponse()->headers->all('vary')),
        );
    }

    public function test_lint_translations_passes(): void
    {
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        $tester->run(['command' => 'lint:translations']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
    }

    public function test_parity_check_reports_the_untranslated_domain(): void
    {
        $container = self::getContainer();
        $translator = $container->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);

        $fixtures = \dirname(__DIR__, 3).'/Fixtures/I18n';

        // Only the probe's domains: the app's real catalogues (e.g. geo) have no French yet.
        self::assertSame(
            ['fr/probe_extra' => ['missing' => ['notice.guests'], 'extra' => []]],
            CatalogueParity::diff(
                $translator,
                'de',
                ['de', 'fr'],
                CatalogueParity::domainsIn([$fixtures.'/translations'], 'de'),
            ),
        );
    }
}
