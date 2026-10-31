<?php

declare(strict_types=1);

namespace App\Tests\Support\Cms;

use App\Shared\ReadModel\ContentBlocks;
use Psr\Cache\CacheItemPoolInterface;

/**
 * `cache.content_blocks` is a filesystem pool shared by every test, and a save refreshes it with
 * rows the test's transaction then rolls back. A test that writes blocks or reads the snapshot
 * calls resetContentBlocks() once the kernel is booted (after createClient() in a WebTestCase)
 * and again in tearDown() before parent::tearDown().
 */
trait ResetsContentBlocks
{
    private function resetContentBlocks(): void
    {
        $container = static::getContainer();

        $pool = $container->get('cache.content_blocks');
        \assert($pool instanceof CacheItemPoolInterface);
        $pool->clear();

        if ($container->has(ContentBlocks::class)) {
            $blocks = $container->get(ContentBlocks::class);
            \assert($blocks instanceof ContentBlocks);
            $blocks->reset();
        }
    }
}
