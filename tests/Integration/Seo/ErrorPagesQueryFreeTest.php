<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Geo\ValueObject\Canton;
use App\Search\ReadModel\LandingFooter;
use App\Search\ReadModel\LandingPage;
use App\Search\ValueObject\LandingType;
use App\Shared\Extension\FooterNoteBlock;
use App\Shared\ReadModel\ContentBlocks;
use App\Tests\Support\Cms\ResetsContentBlocks;
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
 * test the moment it queries. Its links come from the `cache.landing_pages` item, warm or cold,
 * and #26's footer note from the `cache.content_blocks` snapshot (peek only).
 */
final class ErrorPagesQueryFreeTest extends KernelTestCase
{
    use ResetsContentBlocks;

    protected function tearDown(): void
    {
        if (self::$booted) {
            $this->resetContentBlocks();
        }
        parent::tearDown();
    }

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
        $this->resetContentBlocks();

        $html = $this->renderWithoutQuery($template, $status);

        self::assertStringContainsString($heading, $html);
        self::assertStringContainsString('site-footer', $html);
        self::assertStringNotContainsString('footer-cantons', $html);
        self::assertStringNotContainsString('site-footer__note', $html, 'A cold snapshot shows no note and runs no query.');
    }

    #[DataProvider('templates')]
    public function test_a_warm_pool_renders_the_footer_note_without_a_query(string $template, int $status, string $heading): void
    {
        self::bootKernel();
        $this->resetContentBlocks();
        $pool = self::getContainer()->get('cache.content_blocks');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->save($pool->getItem(ContentBlocks::ITEM)->set(['built_at' => time(), 'blocks' => ['de' => [FooterNoteBlock::KEY => 'Alle Angaben **ohne Gewähr**.']]]));

        $html = $this->renderWithoutQuery($template, $status);

        self::assertStringContainsString($heading, $html);
        self::assertStringContainsString('<div class="site-footer__note"><p>Alle Angaben <strong>ohne Gewähr</strong>.</p>', $html);
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
        $request = Request::create('/gibt-es-nicht');
        $request->setLocale('de'); // as the LocaleListener sets it on a real request
        $stack->push($request);
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
