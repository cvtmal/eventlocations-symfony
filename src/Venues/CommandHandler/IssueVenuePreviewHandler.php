<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Funnel\CampaignCode;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\IssueVenuePreview;
use App\Venues\Entity\VenuePreview;
use App\Venues\Exception\VenueNotFound;
use App\Venues\VenueRows;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * G5: stores a new preview link. Nothing public changes (the preview is never cached), so there is
 * no purge and no `cms_edited_at` stamp.
 */
final class IssueVenuePreviewHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueRows $rows,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return string the new link's id
     *
     * @throws VenueNotFound
     */
    public function __invoke(IssueVenuePreview $command): string
    {
        if (null !== $command->campaignCode && !CampaignCode::isValid($command->campaignCode)) {
            throw new \InvalidArgumentException('The campaign code is no plain code.');
        }
        if (!\in_array($command->lifetimeDays, IssueVenuePreview::LIFETIMES, true)) {
            throw new \InvalidArgumentException(\sprintf('A preview link lives %s days, not %d.', implode(', ', IssueVenuePreview::LIFETIMES), $command->lifetimeDays));
        }

        $venue = $this->rows->venue($command->venueNumber);
        // Whole seconds, as the TIMESTAMP(0) columns keep them (PostgreSQL would round, not cut).
        $now = new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp());
        $preview = new VenuePreview($venue, $command->campaignCode, $now->modify(\sprintf('+%d days', $command->lifetimeDays)), $now);
        $this->em->persist($preview);
        $this->em->flush();

        return $preview->id();
    }
}
