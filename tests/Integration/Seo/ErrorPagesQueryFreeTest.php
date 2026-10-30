<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Geo\ValueObject\Canton;
use App\Search\ReadModel\LandingFooter;
use App\Search\ReadModel\LandingPage;
use App\Search\ValueObject\LandingType;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Twig\Environment;

/**
 * The 500 page has to render during a database outage, so no error template may run a query.
 * It renders the full layout, footer included: #17's `seo/_footer_cantons.html.twig` fails this
 * test the moment it queries. Its links come from the `cache.landing_pages` item, warm or cold.
 */
final class ErrorPagesQueryFreeTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function templates(): iterable
    {
        yield 'server error' => ['@Twig/Exception/error.html.twig', 500, 'Da ist etwas schiefgelaufen'];
        yield 'not found' => ['@Twig/Exception/error404.html.twig', 404, 'Diese Seite gibt es nicht'];
        yield 'gone' => ['@Twig/Exception/error410.html.twig', 410, 'Diese Location ist nicht mehr dabei'];
    }

    #[DataProvider('templates')]
    public function test_it_renders_the_whole_layout_without_a_query(string $template, int $status, string $heading): void
    {
        self::bootKernel();
        $this->pool()->clear();

        $html = $this->renderWithoutQuery($template, $status);

        self::assertStringContainsString($heading, $html);
        self::assertStringContainsString('site-footer', $html);
        self::assertStringNotContainsString('footer-cantons', $html);
    }

    #[DataProvider('templates')]
    public function test_a_warm_pool_renders_the_footer_cantons_without_a_query(string $template, int $status, string $heading): void
    {
        self::bootKernel();
        $this->pool()->clear();
        $footer = self::getContainer()->get(LandingFooter::class);
        self::assertInstanceOf(LandingFooter::class, $footer);
        $footer->store([
            new LandingPage(LandingType::Canton, 'zuerich', 12, Canton::ZH, 'zuerich'),
            new LandingPage(LandingType::Canton, 'bern', 7, Canton::BE, 'bern'),
        ]);

        $html = $this->renderWithoutQuery($template, $status);

        self::assertStringContainsString($heading, $html);
        self::assertStringContainsString('Hochzeitslocations nach Kanton', $html);
        self::assertMatchesRegularExpression('#href="/hochzeitslocations/bern">Bern</a>.*href="/hochzeitslocations/zuerich">Zürich</a>#s', $html);
        $this->pool()->clear();
    }

    private function renderWithoutQuery(string $template, int $status): string
    {
        $stack = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push(Request::create('/gibt-es-nicht'));
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        $holder->reset();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render($template, [
            'status_code' => $status,
            'status_text' => 'Error',
            'exception' => new HttpException($status),
        ]);

        self::assertSame([], array_merge(...array_values($holder->getData())));

        return $html;
    }

    private function pool(): CacheItemPoolInterface
    {
        $pool = self::getContainer()->get('cache.landing_pages');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        return $pool;
    }
}
