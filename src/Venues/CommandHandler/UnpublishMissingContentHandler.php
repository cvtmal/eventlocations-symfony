<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\PurgeVenueCache;
use App\Venues\Command\UnpublishMissingContent;
use App\Venues\Repository\VenueRepository;
use App\Venues\ValueObject\PruneOutcome;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Unpublishes every content venue whose folder is gone and forgets its content hash, so a
 * folder that comes back is applied (and published) again, and purges its pages from the
 * HTTP cache after the commit (PurgeVenueCache). Files and rows are kept.
 *
 * A venue the CMS edited since its last import (#24, S8) is kept as it is and reported,
 * unless the command says to overwrite CMS edits. Even then its cms_edited_at stays: a prune
 * applies no content. Outside a dry run each venue's row is locked before its guard is read,
 * as in ImportVenueContentHandler.
 */
final class UnpublishMissingContentHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueRepository $venues,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(UnpublishMissingContent $command): PruneOutcome
    {
        $unpublished = 0;
        $kept = [];
        foreach ($this->venues->contentVenuesMissingFrom($command->presentKeys) as $venue) {
            if (!$command->dryRun) {
                $this->em->refresh($venue, LockMode::PESSIMISTIC_WRITE);
            }
            if (null !== $venue->cmsEditedAt() && !$command->overwriteCmsEdits) {
                $key = $venue->contentKey();
                \assert(null !== $key);
                $kept[] = $key;

                continue;
            }
            ++$unpublished;
            if (!$command->dryRun) {
                $venue->unpublish();
                $venue->forgetContentHash();
                $this->commandBus->dispatch(new PurgeVenueCache($venue->number()), [new DispatchAfterCurrentBusStamp()]);
            }
        }

        return new PruneOutcome($unpublished, $kept);
    }
}
