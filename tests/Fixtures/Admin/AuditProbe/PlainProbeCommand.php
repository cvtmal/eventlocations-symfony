<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Admin\AuditProbe;

final readonly class PlainProbeCommand
{
    public function __construct(
        public string $id,
    ) {
    }
}
