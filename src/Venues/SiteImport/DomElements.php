<?php

declare(strict_types=1);

namespace App\Venues\SiteImport;

use Symfony\Component\DomCrawler\Crawler;

/**
 * The elements of a Crawler selection, at most `$limit` of them: a page is not read element by element without end,
 * and the DOM API hands out nodes of every kind.
 */
final class DomElements
{
    /**
     * @return list<\DOMElement>
     */
    public static function of(Crawler $selection, int $limit = 1000): array
    {
        $elements = [];
        foreach ($selection as $node) {
            if (\count($elements) >= $limit) {
                break;
            }
            if ($node instanceof \DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }
}
