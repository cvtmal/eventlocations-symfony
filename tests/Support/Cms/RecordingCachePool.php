<?php

declare(strict_types=1);

namespace App\Tests\Support\Cms;

use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * An in-memory pool that counts its saves and runs a callback at each one, for what must hold at
 * the moment a snapshot is stored.
 */
final class RecordingCachePool extends ArrayAdapter
{
    public int $saves = 0;

    public ?\Closure $onSave = null;

    public function save(CacheItemInterface $item): bool
    {
        ++$this->saves;
        if (null !== $this->onSave) {
            ($this->onSave)();
        }

        return parent::save($item);
    }
}
