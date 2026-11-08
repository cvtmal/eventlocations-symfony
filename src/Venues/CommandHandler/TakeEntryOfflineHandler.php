<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\TakeEntryOffline;
use App\Venues\Command\UnpublishVenue;
use App\Venues\Exception\ModerationCaseAlreadyDecided;
use App\Venues\Exception\ModerationCaseWithoutVenue;
use App\Venues\Repository\ModerationCaseRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * G2: `UnpublishVenue` is nested, so the entry goes offline the way the CMS takes it offline (its own audit row,
 * the purges after the commit), and the case remembers it did. Only an open case of a venue may do this.
 */
final class TakeEntryOfflineHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly ModerationCaseRepository $cases,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return bool whether this took the entry offline (false: it was offline already)
     */
    public function __invoke(TakeEntryOffline $command): bool
    {
        $case = $this->cases->get($command->caseId);
        if (!$case->isOpen()) {
            throw ModerationCaseAlreadyDecided::forId($command->caseId);
        }
        $venue = $case->venue() ?? throw ModerationCaseWithoutVenue::forId($command->caseId);
        if (!$venue->isPublished()) {
            return false;
        }

        $this->commandBus->dispatch(new UnpublishVenue($venue->number()));
        $case->recordEntryTakenOffline($this->clock->now());

        return true;
    }
}
