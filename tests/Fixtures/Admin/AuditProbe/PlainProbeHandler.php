<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Admin\AuditProbe;

use App\Shared\Messenger\CommandHandlerInterface;

final class PlainProbeHandler implements CommandHandlerInterface
{
    public function __invoke(PlainProbeCommand $command): void
    {
    }
}
