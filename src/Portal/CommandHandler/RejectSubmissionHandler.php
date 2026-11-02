<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Admin\Repository\AdminUserRepository;
use App\Portal\Command\RejectSubmission;
use App\Portal\Exception\SubmissionNotOpen;
use App\Portal\Repository\SubmissionRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Repository\VenueRepository;
use Psr\Clock\ClockInterface;

final class RejectSubmissionHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueRepository $venues,
        private readonly SubmissionRepository $submissions,
        private readonly AdminUserRepository $staff,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(RejectSubmission $command): void
    {
        $venueId = $this->venues->idByNumber($command->venueNumber) ?? throw VenueNotFound::forNumber($command->venueNumber);
        $submission = $this->submissions->ofVenue($venueId);
        if (null === $submission || !$submission->isSubmitted()) {
            throw SubmissionNotOpen::forNumber($command->venueNumber);
        }

        $submission->reject($this->staff->get($command->staffUserId), $command->reason, $this->clock->now());
    }
}
