<?php

declare(strict_types=1);

namespace App\Shared\Twig;

use App\Shared\Translation\HreflangAlternate;
use App\Shared\Translation\HreflangAlternates;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

/**
 * `hreflang_alternates(query = {})` for templates/shared/_hreflang.html.twig. Uses the main
 * request, so the page URL is used inside sub-requests too. A request that is not safe gets
 * none: a POST re-render (#14's 422, 429 and 503) must not advertise its POST URL.
 */
final readonly class HreflangExtension
{
    public function __construct(
        private RequestStack $requestStack,
        private HreflangAlternates $alternates,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<HreflangAlternate>
     */
    #[AsTwigFunction('hreflang_alternates')]
    public function alternates(array $query = []): array
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request || !$request->isMethodSafe()) {
            return [];
        }

        return $this->alternates->forRequest($request, $query);
    }
}
