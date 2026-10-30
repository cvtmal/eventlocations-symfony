<?php

declare(strict_types=1);

namespace App\Shared\Messenger;

/**
 * Marker for command handlers. Implementing it registers the class on command.bus
 * (see the _instanceof block in config/services.yaml).
 */
interface CommandHandlerInterface
{
}
