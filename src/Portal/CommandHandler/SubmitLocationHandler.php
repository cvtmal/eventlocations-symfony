<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\SubmitLocation;
use App\Portal\Entity\Submission;
use App\Portal\Repository\PortalUserRepository;
use App\Portal\Repository\SubmissionRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Entity\Venue;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\ReadModel\VenuePublishGaps;
use App\Venues\Repository\VenueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Checks the rule `PublishVenue` will check, so staff get no location Accept must refuse. A claimed location has no
 * row until its first submit.
 */
final class SubmitLocationHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueRepository $venues,
        private readonly VenuePublishGaps $gaps,
        private readonly SubmissionRepository $submissions,
        private readonly PortalUserRepository $users,
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

        return $submission->submit($this->users->get($command->portalUserId), $now);
    }
}
