<?php

declare(strict_types=1);

namespace App\Seo;

/**
 * SEO-2: a BreadcrumbList. The last item, the page itself, may omit its URL.
 */
final class BreadcrumbJsonLd
{
    /**
     * @param list<array{name: string, url?: string}> $items
     *
     * @return array<string, mixed>
     */
    public static function build(array $items): array
    {
        $elements = [];
        foreach ($items as $index => $item) {
            $element = ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $item['name']];
            if (isset($item['url'])) {
                $element['item'] = $item['url'];
            }
            $elements[] = $element;
        }

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $elements];
    }
}
