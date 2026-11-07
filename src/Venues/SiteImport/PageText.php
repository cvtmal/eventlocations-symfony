<?php

declare(strict_types=1);

namespace App\Venues\SiteImport;

/**
 * The visible text of a DOM subtree as lines: one line per block element and per <br>, scripts and styles left out.
 * The address, phone and snippet readers work line by line, which `textContent` (one long string) cannot give them.
 */
final class PageText
{
    private const array SKIP = ['script', 'style', 'noscript', 'template', 'svg', 'head', 'iframe', 'select', 'option'];
    private const array BREAKS = [
        'p', 'div', 'li', 'ul', 'ol', 'tr', 'table', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'br', 'address', 'section',
        'article', 'footer', 'header', 'nav', 'main', 'aside', 'blockquote', 'dd', 'dt', 'dl', 'form', 'figure', 'figcaption', 'hr',
    ];
    private const int MAX_DEPTH = 150;

    /**
     * @return list<string> trimmed, non-empty lines
     */
    public static function lines(\DOMNode $root, int $maxBytes = 150_000): array
    {
        $out = '';
        self::walk($root, $out, $maxBytes, 0);

        $lines = [];
        foreach (explode("\n", $out) as $line) {
            $line = trim((string) preg_replace('/[\h\x{00A0}]+/u', ' ', $line));
            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private static function walk(\DOMNode $node, string &$out, int $maxBytes, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        foreach ($node->childNodes as $child) {
            if (\strlen($out) >= $maxBytes) {
                return;
            }
            if ($child instanceof \DOMText) {
                $out .= (string) preg_replace('/\s+/u', ' ', $child->nodeValue ?? '');
            } elseif ($child instanceof \DOMElement) {
                $name = strtolower($child->nodeName);
                if (\in_array($name, self::SKIP, true)) {
                    continue;
                }
                $break = \in_array($name, self::BREAKS, true);
                $out .= $break ? "\n" : '';
                self::walk($child, $out, $maxBytes, $depth + 1);
                $out .= $break ? "\n" : ('td' === $name || 'th' === $name ? ' ' : '');
            }
        }
    }
}
