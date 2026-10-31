<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\UnlinkVenue;
use App\Crm\Repository\CompanyVenueRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class UnlinkVenueHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyVenueRepository $links,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(UnlinkVenue $command): void
    {
        $link = Uuid::isValid($command->venueId) ? $this->links->forVenue($command->venueId) : null;
        if (null === $link || $link->company()->id() !== $command->companyId) {
            return;
        }

        $this->em->remove($link);
    }
}
