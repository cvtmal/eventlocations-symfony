<?php

declare(strict_types=1);

namespace App\Stats;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where the beacon's files live (#47): `stats.dir` (var/stats; the test cache dir in test), with the
 * day files in `spool/` and the visitor-hash salts in `salt/`. var/ survives a deploy.
 */
final readonly class StatsPaths
{
    public function __construct(
        #[Autowire('%stats.dir%')]
        private string $directory,
    ) {
    }

    public function spoolDir(): string
    {
        return rtrim($this->directory, '/').'/spool';
    }

    public function saltDir(): string
    {
        return rtrim($this->directory, '/').'/salt';
    }
}
