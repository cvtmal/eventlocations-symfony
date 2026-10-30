<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\Twig\AdminUrlExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

final class AdminUrlExtensionTest extends TestCase
{
    public function test_it_merges_into_the_current_query_and_keeps_the_route(): void
    {
        self::assertSame(['admin_list', ['status' => 'draft', 'sort' => 'name', 'id' => '7']], self::path(['status' => 'draft'], ['sort' => 'name']));
    }

    public function test_null_removes_a_key(): void
    {
        self::assertSame(['admin_list', ['id' => '7']], self::path(['status' => 'draft'], ['status' => null]));
    }

    public function test_a_change_goes_back_to_page_one(): void
    {
        self::assertSame(['admin_list', ['sort' => 'name', 'id' => '7']], self::path(['page' => '4'], ['sort' => 'name']));
    }

    public function test_page_stays_when_the_change_names_it(): void
    {
        self::assertSame(['admin_list', ['status' => 'draft', 'page' => 5, 'id' => '7']], self::path(['status' => 'draft', 'page' => '4'], ['page' => 5]));
        self::assertSame(['admin_list', ['id' => '7']], self::path(['page' => '4'], ['page' => null]));
    }

    public function test_the_query_cannot_replace_a_route_parameter(): void
    {
        self::assertSame(['admin_list', ['id' => '7']], self::path(['id' => '999'], []));
    }

    public function test_it_needs_a_request(): void
    {
        $extension = new AdminUrlExtension(new RequestStack(), self::recordingUrls());

        $this->expectException(\LogicException::class);

        $extension->queryPath();
    }

    public function test_page_links_are_the_shared_window(): void
    {
        $extension = new AdminUrlExtension(new RequestStack(), self::recordingUrls());

        self::assertSame([1, null, 4, 5, 6, null, 20], $extension->pageLinks(5, 20));
    }

    /**
     * @param array<string, string>          $query
     * @param array<string, int|string|null> $changes
     *
     * @return array{string, array<string, mixed>} route and parameters given to the generator
     */
    private static function path(array $query, array $changes): array
    {
        $request = new Request($query);
        $request->attributes->set('_route', 'admin_list');
        $request->attributes->set('_route_params', ['id' => '7']);

        $requests = new RequestStack();
        $requests->push($request);

        $generated = (new AdminUrlExtension($requests, self::recordingUrls()))->queryPath($changes);

        /** @var array{string, array<string, mixed>} $call */
        $call = json_decode($generated, true, flags: \JSON_THROW_ON_ERROR);

        return $call;
    }

    private static function recordingUrls(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return (string) json_encode([$name, $parameters]);
            }

            public function setContext(RequestContext $context): void
            {
            }

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }
        };
    }
}
