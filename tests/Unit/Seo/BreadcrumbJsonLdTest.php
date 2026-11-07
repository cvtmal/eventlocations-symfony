<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use App\Seo\BreadcrumbJsonLd;
use PHPUnit\Framework\TestCase;

final class BreadcrumbJsonLdTest extends TestCase
{
    public function test_positions_start_at_one_and_the_last_item_has_no_url(): void
    {
        $data = BreadcrumbJsonLd::build([
            ['name' => 'Hochzeitslocations', 'url' => 'https://example.ch/eventlokale'],
            ['name' => 'Schloss Seeblick'],
        ]);

        self::assertSame('BreadcrumbList', $data['@type']);
        self::assertSame([
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Hochzeitslocations', 'item' => 'https://example.ch/eventlokale'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Schloss Seeblick'],
        ], $data['itemListElement']);
    }
}
