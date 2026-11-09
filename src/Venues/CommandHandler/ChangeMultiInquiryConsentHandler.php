<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ChangeMultiInquiryConsent;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Repository\VenueRepository;

final class ChangeMultiInquiryConsentHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueRepository $venues,
    ) {
    }

    public function __invoke(ChangeMultiInquiryConsent $command): void
    {
        $venue = $this->venues->findOneBy(['number' => $command->venueNumber]) ?? throw VenueNotFound::forNumber($command->venueNumber);
        $venue->changeMultiInquiryConsent($command->accepts);
    }
}
