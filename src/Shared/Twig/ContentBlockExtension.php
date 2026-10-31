<?php

declare(strict_types=1);

namespace App\Shared\Twig;

use App\Shared\Extension\ContentBlockRegistry;
use App\Shared\ReadModel\ContentBlocks;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Staff-edited blocks in templates (#26), in the main request's locale:
 *
 * - `content_block(key, fallback = null)`: the block as safe HTML (MarkdownRenderer), else the
 *   escaped fallback in a `<p>`, else nothing. The fallback is the caller's (a landing page has
 *   no definition), else the ContentBlockDefinition's. May query once per request on a cold pool.
 * - `content_block_peek(key)`: the same from the pool only, never a query (footer, error pages).
 * - `content_text(key)`: the raw text, else the definition's fallback, for a value that is not
 *   Markdown (a title).
 */
final class ContentBlockExtension
{
    public function __construct(
        private readonly ContentBlocks $blocks,
        private readonly ContentBlockRegistry $definitions,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $defaultLocale,
    ) {
    }

    #[AsTwigFunction('content_block', isSafe: ['html'])]
    public function block(string $key, ?string $fallback = null): string
    {
        return $this->blocks->html($key, $this->locale()) ?? $this->fallbackHtml($key, $fallback);
    }

    #[AsTwigFunction('content_block_peek', isSafe: ['html'])]
    public function peek(string $key): string
    {
        return $this->blocks->peekHtml($key, $this->locale()) ?? $this->fallbackHtml($key, null);
    }

    #[AsTwigFunction('content_text')]
    public function text(string $key): string
    {
        return $this->blocks->text($key, $this->locale()) ?? $this->declaredFallback($key) ?? '';
    }

    private function fallbackHtml(string $key, ?string $fallback): string
    {
        $text = $fallback ?? $this->declaredFallback($key);
        if (null === $text || '' === trim($text)) {
            return '';
        }

        return '<p>'.htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8').'</p>';
    }

    private function declaredFallback(string $key): ?string
    {
        return $this->definitions->get($key)?->fallback()?->trans($this->translator, $this->locale());
    }

    private function locale(): string
    {
        return $this->requestStack->getMainRequest()?->getLocale() ?? $this->defaultLocale;
    }
}
