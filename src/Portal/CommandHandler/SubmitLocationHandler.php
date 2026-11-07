<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\SubmitLocation;
use App\Portal\Entity\Submission;
use App\Portal\Repository\PortalUserRepository;
use App\Portal\Repository\SubmissionRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Translation\EnabledLocale;
use App\Venues\Command\GrantPhotoRights;
use App\Venues\Entity\Venue;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\ReadModel\PhotoRightsQuery;
use App\Venues\ReadModel\VenuePublishGaps;
use App\Venues\Repository\VenueRepository;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Checks the rule `PublishVenue` will check, so staff get no location Accept must refuse. A claimed location has no
 * row until its first submit.
 *
 * The press is also the manager's rights declaration (G1): once the review started, the photos without a live grant
 * are granted in the same transaction (evidence `declaration`, `GrantPhotoRights` nested as `AcceptSubmissionHandler`
 * nests `PublishVenue`). A resubmit grants only what has none yet, and a press that starts no review grants nothing.
 * The nested command is dispatched only when a photo is waiting, because it is audited and a no-op would still write
 * a row.
 */
final class SubmitLocationHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueRepository $venues,
        private readonly VenuePublishGaps $gaps,
        private readonly SubmissionRepository $submissions,
        private readonly PortalUserRepository $users,
        private readonly PhotoRightsQuery $rights,
        private readonly EnabledLocale $locales,
        private readonly MessageBusInterface $commandBus,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return bool whether the review started; false when it was already running
     */
    public function __invoke(SubmitLocation $command): bool
    {
        $venueId = $this->venues->idByNumber($command->venueNumber) ?? throw VenueNotFound::forNumber($command->venueNumber);

        $missing = $this->gaps->missing($venueId);
        if ([] !== $missing) {
            throw VenueRuleViolation::notPublishable($missing);
        }

        $now = $this->clock->now();
        $submission = $this->submissions->ofVenue($venueId);
        if (null === $submission) {
            $submission = new Submission($this->em->getReference(Venue::class, $venueId) ?? throw VenueNotFound::forNumber($command->venueNumber), $now);
            $this->em->persist($submission);
        }

        if (!$submission->submit($this->users->get($command->portalUserId), $now)) {
            return false;
        }

        $rights = $this->rights->forVenue($command->venueNumber, $this->locales->default());
        if (null !== $rights && $rights->ungrantedCount > 0) {
            $this->commandBus->dispatch(new GrantPhotoRights($command->venueNumber, Grantor::manager($command->portalUserId), GrantEvidence::Declaration, $command->derivatives));
        }

        return true;
    }
}
