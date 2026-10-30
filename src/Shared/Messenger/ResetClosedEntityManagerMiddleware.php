<?php

declare(strict_types=1);

namespace App\Shared\Messenger;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Replaces a closed EntityManager before the next command is handled.
 *
 * A failed flush closes the EntityManager, and every later dispatch in the same process
 * (one per venue in app:venues:import) would fail with it. Symfony's
 * doctrine_ping_connection middleware resets only for messages consumed by a worker
 * (ConsumedByWorkerStamp), never for synchronous dispatches, so this one does it. It runs
 * before doctrine_transaction (config/packages/messenger.yaml).
 */
final class ResetClosedEntityManagerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ManagerRegistry $registry,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $manager = $this->registry->getManager();
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $this->registry->resetManager();
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
