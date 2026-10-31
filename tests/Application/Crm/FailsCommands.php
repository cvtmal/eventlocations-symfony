<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * For the paths only a race reaches: the form or the check has passed, then the handler refuses. The
 * bus answers as the real one does, with the exception keyed by handler name. The client must not
 * reboot (`AdminSession` disables it), and this comes first in a test, before the first request
 * initialises the bus.
 */
trait FailsCommands
{
    private function commandsFailWith(\Throwable $thrown): void
    {
        self::getContainer()->set('command.bus', new class($thrown) implements MessageBusInterface {
            public function __construct(private readonly \Throwable $thrown)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new HandlerFailedException(new Envelope($message), ['App\Crm\CommandHandler\Handler::__invoke' => $this->thrown]);
            }
        });
    }
}
