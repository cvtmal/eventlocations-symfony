<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Translation\EnabledLocale;
use App\Venues\Command\DescribeVenuePhoto;
use App\Venues\Entity\VenuePhotoTranslation;
use App\Venues\RowTokens;
use App\Venues\VenueEditing;
use App\Venues\VenueRows;
use Doctrine\ORM\EntityManagerInterface;

/**
 * #25, D13, D16, PF2: the photo's own token decides staleness, so an upload of another photo
 * in the same island does not stale this save. A text is trimmed; an empty one removes the
 * locale's row. Nothing changed: no touch, no close(). An alt of a gallery photo is on the
 * cards, so it purges the listings (D19).
 */
final class DescribeVenuePhotoHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
        private readonly EnabledLocale $locales,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(DescribeVenuePhoto $command): void
    {
        foreach (array_keys($command->alts) as $locale) {
            if (!$this->locales->isEnabled($locale)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not an enabled locale.', $locale));
            }
        }

        $venue = $this->editing->lock($command->venueNumber);
        $photo = $this->rows->photo($venue, $command->photoId);
        RowTokens::assertFresh($photo->updatedAt(), $command->loadedAt, $command->venueNumber);

        $existing = [];
        foreach ($this->em->getRepository(VenuePhotoTranslation::class)->findBy(['photo' => $photo]) as $translation) {
            $existing[$translation->locale()] = $translation;
        }

        $changed = false;
        foreach ($command->alts as $locale => $alt) {
            $alt = trim($alt);
            $translation = $existing[$locale] ?? null;
            if ('' === $alt) {
                if (null !== $translation) {
                    $this->em->remove($translation);
                    $changed = true;
                }
            } elseif (null === $translation) {
                $this->em->persist(new VenuePhotoTranslation($photo, $locale, $alt));
                $changed = true;
            } elseif ($translation->alt() !== $alt) {
                $translation->rewrite($alt);
                $changed = true;
            }
        }
        if (!$changed) {
            return;
        }

        $photo->touch();
        $this->editing->close($venue, purgeListings: null === $photo->room());
    }
}
