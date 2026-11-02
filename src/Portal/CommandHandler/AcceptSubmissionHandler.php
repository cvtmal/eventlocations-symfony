<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Admin\Repository\AdminUserRepository;
use App\Portal\Command\AcceptSubmission;
use App\Portal\Exception\SubmissionNotOpen;
use App\Portal\Repository\SubmissionRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\PublishVenue;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\Repository\VenueRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Editing stays open during the review, so a location can lose its last photo. `PublishVenue` then refuses with the
 * named gaps, the whole command rolls back, and the location stays in the queue. The answer is `PublishVenue`'s.
 */
final class AcceptSubmissionHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueRepository $venues,
        private readonly SubmissionRepository $submissions,
        private readonly AdminUserRepository $staff,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return bool whether this was the first publish of a venue `CreateVenue` made (Q20)
     */
    public function __invoke(AcceptSubmission $command): bool
    {
        $venueId = $this->venues->idByNumber($command->venueNumber) ?? throw VenueNotFound::forNumber($command->venueNumber);
        $submission = $this->submissions->ofVenue($venueId);
        if (null === $submission || !$submission->isSubmitted()) {
            throw SubmissionNotOpen::forNumber($command->venueNumber);
        }

        try {
            $first = true === $this->commandBus->dispatch(new PublishVenue($command->venueNumber))->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failure) {
            throw VenueRuleViolation::unwrap($failure);
        }

        $submission->accept($this->staff->get($command->staffUserId), $this->clock->now());

        return $first;
    }
}
