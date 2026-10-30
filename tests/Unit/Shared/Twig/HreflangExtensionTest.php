<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Twig;

use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\HreflangAlternates;
use App\Shared\Twig\HreflangExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class HreflangExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function safeMethods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
    }

    #[DataProvider('safeMethods')]
    public function test_a_safe_request_gets_alternates(string $method): void
    {
        self::assertCount(2, $this->extension($this->request($method))->alternates());
    }

    public function test_a_post_re_render_gets_none(): void
    {
        self::assertSame([], $this->extension($this->request('POST'))->alternates());
    }

    public function test_no_main_request_gets_none(): void
    {
        self::assertSame([], $this->extension(null)->alternates());
    }

    private function request(string $method): Request
    {
        $request = new Request(attributes: ['_route' => 'p', '_route_params' => ['_locale' => 'de']]);
        $request->setMethod($method);

        return $request;
    }

    private function extension(?Request $request): HreflangExtension
    {
        $routes = new RouteCollection();
        $routes->add('p.de', new Route('/p', ['_locale' => 'de', '_canonical_route' => 'p']));
        $generator = new UrlGenerator($routes, RequestContext::fromUri('https://example.ch'));

        $stack = new RequestStack();
        if (null !== $request) {
            $stack->push($request);
        }

        return new HreflangExtension($stack, new HreflangAlternates($generator, new EnabledLocale(['de'], 'de')));
    }
}
