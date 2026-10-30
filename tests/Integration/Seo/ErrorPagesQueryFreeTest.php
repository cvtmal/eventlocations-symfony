<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Twig\Environment;

/**
 * The 500 page has to render during a database outage, so no error template may run a query.
 * It renders the full layout, footer included: #17's `seo/_footer_cantons.html.twig` fails this
 * test the moment it queries.
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

        self::assertStringContainsString($heading, $html);
        self::assertStringContainsString('site-footer', $html);
        self::assertSame([], array_merge(...array_values($holder->getData())));
    }
}
