<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\AnswerVenueFaq;
use App\Venues\VenueEditing;

final class AnswerVenueFaqHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
    ) {
    }

    public function __invoke(AnswerVenueFaq $command): void
    {
        $venue = $this->editing->open($command->venueNumber, $command->loadedAt);

        $venue->answerFaq($command->ownCatering, $command->daylight);

        $this->editing->close($venue);
    }
}
