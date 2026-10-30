<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Translation;

use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\HreflangAlternate;
use App\Shared\Translation\HreflangAlternates;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class HreflangAlternatesTest extends TestCase
{
    public function test_german_only_lists_de_ch_and_x_default(): void
    {
        self::assertSame([
            ['de-CH', 'https://example.ch/p'],
            ['x-default', 'https://example.ch/p'],
        ], $this->pairs($this->alternates(['de'])->forRequest($this->request())));
    }

    public function test_every_enabled_locale_gets_an_alternate_before_x_default(): void
    {
        self::assertSame([
            ['de-CH', 'https://example.ch/p'],
            ['fr-CH', 'https://example.ch/fr/p'],
            ['x-default', 'https://example.ch/p'],
        ], $this->pairs($this->alternates(['de', 'fr'])->forRequest($this->request())));
    }

    public function test_locale_without_a_path_is_skipped(): void
    {
        self::assertSame([
            ['de-CH', 'https://example.ch/p'],
            ['x-default', 'https://example.ch/p'],
        ], $this->pairs($this->alternates(['de', 'fr'], withFrench: false)->forRequest($this->request())));
    }

    public function test_request_without_a_route_has_no_alternates(): void
    {
        self::assertSame([], $this->alternates(['de'])->forRequest(new Request()));
    }

    public function test_route_without_locale_has_no_alternates(): void
    {
        $request = new Request(attributes: ['_route' => 'health', '_route_params' => []]);

        self::assertSame([], $this->alternates(['de'])->forRequest($request));
    }

    public function test_query_parameters_are_appended_without_internal_defaults(): void
    {
        $alternates = $this->alternates(['de', 'fr'])->forRequest($this->request(), ['page' => 2]);

        self::assertSame([
            ['de-CH', 'https://example.ch/p?page=2'],
            ['fr-CH', 'https://example.ch/fr/p?page=2'],
            ['x-default', 'https://example.ch/p?page=2'],
        ], $this->pairs($alternates));
        foreach ($alternates as $alternate) {
            self::assertStringNotContainsString('_canonical_route', $alternate->href);
            self::assertStringNotContainsString('_locale', $alternate->href);
        }
    }

    /**
     * @param list<string> $enabledLocales
     */
    private function alternates(array $enabledLocales, bool $withFrench = true): HreflangAlternates
    {
        $routes = new RouteCollection();
        $routes->add('p.de', new Route('/p', ['_locale' => 'de', '_canonical_route' => 'p']));
        if ($withFrench) {
            $routes->add('p.fr', new Route('/fr/p', ['_locale' => 'fr', '_canonical_route' => 'p']));
        }

        $generator = new UrlGenerator($routes, RequestContext::fromUri('https://example.ch'));

        return new HreflangAlternates($generator, new EnabledLocale($enabledLocales, 'de'));
    }

    /**
     * The attributes the router sets for a match of `p.de`: the matcher reports the canonical
     * name as `_route` and drops `_canonical_route`.
     */
    private function request(): Request
    {
        return new Request(attributes: [
            '_route' => 'p',
            '_route_params' => ['_locale' => 'de'],
        ]);
    }

    /**
     * @param list<HreflangAlternate> $alternates
     *
     * @return list<array{string, string}>
     */
    private function pairs(array $alternates): array
    {
        return array_map(static fn (HreflangAlternate $a): array => [$a->hreflang, $a->href], $alternates);
    }
}
