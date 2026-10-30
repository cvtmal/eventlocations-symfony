<?php

declare(strict_types=1);

namespace App\Admin\Twig;

use App\Shared\ValueObject\PageLinks;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * URLs of list screens. Sort links, pagination, status tabs and chip removal all change one part of the
 * current query, so they share `admin_query_path()`.
 */
final class AdminUrlExtension
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * The current page with `changes` merged into its query: null removes a key, and `page` goes unless
     * `changes` names it, because a new filter or sort starts at page one.
     *
     * @param array<string, mixed> $changes
     */
    #[AsTwigFunction('admin_query_path')]
    public function queryPath(array $changes = []): string
    {
        $request = $this->requests->getMainRequest() ?? throw new \LogicException('admin_query_path() needs a request.');

        $query = $request->query->all();
        if (!\array_key_exists('page', $changes)) {
            unset($query['page']);
        }

        foreach ($changes as $key => $value) {
            if (null === $value) {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }

        /** @var array<string, mixed> $routeParameters */
        $routeParameters = $request->attributes->get('_route_params', []);

        return $this->urls->generate((string) $request->attributes->get('_route'), [...$query, ...$routeParameters]);
    }

    /**
     * @return list<int|null> page numbers, null for a gap
     */
    #[AsTwigFunction('admin_page_links')]
    public function pageLinks(int $page, int $lastPage): array
    {
        return PageLinks::around($page, $lastPage);
    }
}
