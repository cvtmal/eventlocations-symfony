<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ChangeVenueContact;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\VenueEditing;

/**
 * D60: an online venue needs its inquiry address (`VenuePublishGaps`). A save that empties it is refused, so the
 * venue stays online with the old one; taking it offline is a separate, deliberate step.
 */
final class ChangeVenueContactHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
    ) {
    }

    public function __invoke(ChangeVenueContact $command): void
    {
        $venue = $this->editing->open($command->venueNumber, $command->loadedAt);
        if (null === $command->inquiryEmail && $venue->isPublished()) {
            throw VenueRuleViolation::inquiryEmailRequired();
        }

        $venue->changeContact($command->phone, $command->inquiryEmail, $command->website, $command->contactPerson);
        $venue->changeInstagram($command->instagram);
        $venue->changeMultiInquiryConsent($command->acceptsMultiInquiries);

        $this->editing->close($venue);
    }
}
