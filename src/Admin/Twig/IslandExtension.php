<?php

declare(strict_types=1);

namespace App\Admin\Twig;

use Twig\Attribute\AsTwigFunction;

/**
 * `{{ admin_island('hello', {greeting: '…'|trans({}, 'admin')}) }}` renders a Vue island's mount point;
 * strings arrive as translated props (S4). `fallback` is markup for the time before the mount and without
 * JS, such as a plain form field. `admin_island_deferred()` renders a mount point that waits for first use
 * (the command palette) and loads nothing until then. `admin_island_assets()` closes the page: it renders
 * nothing unless an island was used. The nonce parameter is for #16's CSP.
 */
final class IslandExtension
{
    private const string BASE = '/admin-build/';

    public function __construct(
        private readonly IslandManifest $manifest,
    ) {
    }

    /**
     * @param array<string, mixed> $props
     */
    #[AsTwigFunction('admin_island', isSafe: ['html'])]
    public function island(string $name, array $props = [], string|\Stringable $fallback = ''): string
    {
        $this->manifest->markUsed($name);

        return \sprintf(
            '<div data-island="%s" data-props="%s">%s</div>',
            self::escape($name),
            self::props($props),
            $fallback,
        );
    }

    /**
     * @param array<string, mixed> $props
     */
    #[AsTwigFunction('admin_island_deferred', isSafe: ['html'])]
    public function deferred(string $name, array $props = []): string
    {
        return \sprintf(
            '<div data-island="%s" data-props="%s" data-island-defer="%s"></div>',
            self::escape($name),
            self::props($props),
            self::escape(self::BASE.$this->manifest->deferredEntry($name)),
        );
    }

    #[AsTwigFunction('admin_island_assets', isSafe: ['html'])]
    public function assets(?string $nonce = null): string
    {
        if (!$this->manifest->anyUsed()) {
            return '';
        }

        $nonceAttribute = null === $nonce ? '' : \sprintf(' nonce="%s"', self::escape($nonce));
        $tags = [];
        foreach ($this->manifest->preloadFiles() as $file) {
            $tags[] = \sprintf('<link rel="modulepreload" href="%s"%s>', self::escape(self::BASE.$file), $nonceAttribute);
        }
        $tags[] = \sprintf('<script type="module" src="%s"%s></script>', self::escape(self::BASE.$this->manifest->entryFile()), $nonceAttribute);

        return implode("\n", $tags);
    }

    /**
     * @param array<string, mixed> $props
     */
    private static function props(array $props): string
    {
        return self::escape(json_encode((object) $props, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
