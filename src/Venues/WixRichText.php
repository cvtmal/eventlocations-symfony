<?php

declare(strict_types=1);

namespace App\Venues;

/**
 * The text of a Wix rich-text field (`{"nodes": [...]}`). Lists nest, so the walk is
 * recursive; an IMAGE is skipped with its CAPTION. Text comes out in NFC and without U+200B,
 * which changes bytes, not spelling: decomposed labels would otherwise not match.
 */
final class WixRichText
{
    /**
     * @return list<string> the non-empty paragraphs; consecutive list items form one, by line
     *
     * @throws \JsonException
     */
    public static function paragraphs(string $json): array
    {
        $document = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);

        $blocks = [];
        self::collect(\is_array($document) ? self::children($document) : [], false, $blocks);

        $paragraphs = [];
        $previousWasListItem = false;
        foreach ($blocks as [$text, $isListItem]) {
            if ($isListItem && $previousWasListItem) {
                $paragraphs[\count($paragraphs) - 1] .= "\n".$text;
            } else {
                $paragraphs[] = $text;
            }
            $previousWasListItem = $isListItem;
        }

        return $paragraphs;
    }

    /**
     * @param list<array<mixed>>        $nodes
     * @param list<array{string, bool}> $blocks text and whether it is a list item, in order
     */
    private static function collect(array $nodes, bool $inList, array &$blocks): void
    {
        foreach ($nodes as $node) {
            $type = $node['type'] ?? null;
            if ('IMAGE' === $type) {
                continue;
            }

            $text = '';
            $inner = [];
            foreach (self::children($node) as $child) {
                if ('TEXT' === ($child['type'] ?? null)) {
                    $data = $child['textData'] ?? null;
                    $text .= \is_array($data) && \is_string($data['text'] ?? null) ? $data['text'] : '';
                } else {
                    $inner[] = $child;
                }
            }

            $text = self::clean($text);
            if ('' !== $text) {
                $blocks[] = [$text, $inList];
            }
            self::collect($inner, $inList || 'LIST_ITEM' === $type, $blocks);
        }
    }

    /**
     * @param array<mixed> $node
     *
     * @return list<array<mixed>>
     */
    private static function children(array $node): array
    {
        $nodes = $node['nodes'] ?? null;

        return \is_array($nodes) ? array_values(array_filter($nodes, is_array(...))) : [];
    }

    private static function clean(string $text): string
    {
        $normalized = \Normalizer::normalize($text, \Normalizer::NFC);
        $visible = str_replace("\u{200B}", '', \is_string($normalized) ? $normalized : $text);

        // Not trim(): it does not see U+00A0, and Wix leaves paragraphs of nothing else.
        return preg_replace('/^\s+|\s+$/u', '', $visible) ?? $visible;
    }
}
