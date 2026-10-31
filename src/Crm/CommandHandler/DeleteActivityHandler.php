<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\DeleteActivity;
use App\Crm\OwnActivity;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A hard delete (D14): its links go with it through the foreign keys' cascade.
 */
final class DeleteActivityHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly OwnActivity $own,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(DeleteActivity $command): void
    {
        $this->em->remove($this->own->lock($command->id, $command->actingUserId));
    }
}
