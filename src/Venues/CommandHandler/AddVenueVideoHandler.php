<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\AddVenueVideo;
use App\Venues\Entity\VenueVideo;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\Positions;
use App\Venues\TierLimits;
use App\Venues\ValueObject\VideoEmbed;
use App\Venues\VenueEditing;
use App\Venues\VenueRows;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One video (G15): the link must be on `VideoEmbed`'s allowlist (an unlisted Vimeo link is videoNotPublic()), then
 * lock, the same video again is videoAlreadyAdded(), the plan's `videos_max` (F12, `TierLimits`), add at the next
 * position, close() without the listings purge (videos are not on the cards).
 * uk_venue_videos_venue_id_provider_video_id backs the duplicate check.
 */
final class AddVenueVideoHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
        private readonly Positions $positions,
        private readonly EntityManagerInterface $em,
        private readonly TierLimits $limits,
    ) {
    }

    /**
     * @return string the new video's row id
     *
     * @throws VenueNotFound
     * @throws VenueRuleViolation
     */
    public function __invoke(AddVenueVideo $command): string
    {
        $embed = VideoEmbed::fromUrl($command->url);
        if (null === $embed) {
            throw VideoEmbed::isUnlistedVimeo($command->url) ? VenueRuleViolation::videoNotPublic() : VenueRuleViolation::videoUrlNotAllowed();
        }
        $venue = $this->editing->lock($command->venueNumber);
        foreach ($this->rows->videos($venue) as $video) {
            if ($video->provider() === $embed->provider && $video->videoId() === $embed->videoId) {
                throw VenueRuleViolation::videoAlreadyAdded();
            }
        }
        $this->limits->assertVideoFits($venue);

        $video = new VenueVideo($venue, $this->positions->nextVideo($venue), $embed);
        $this->em->persist($video);
        $this->em->flush();
        $this->editing->close($venue, purgeListings: false);

        return $video->id();
    }
}
