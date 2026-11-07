<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\PurgeListingsCache;
use App\Venues\Command\ReviseTier;
use App\Venues\Entity\Tier;
use App\Venues\Exception\TierNameTaken;
use App\Venues\Exception\TierNotFound;
use App\Venues\Repository\TierRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Writes a staff edit of a tier (F12). Nothing is locked: at most two staff work in the back office, and the last
 * save wins. A save that changes no stored value returns before it touches the row or the cache.
 *
 * A name is unique (`uk_tiers_name`). The form checks it first; this handler checks it again, and the flush is its own
 * step so that the key, which decides under concurrency, ends as `TierNameTaken` too and never as a 500.
 *
 * A changed limit or flag changes every venue page of that tier (photos, contact data, the "Top-Eintrag" label), and
 * a changed rank weight reorders the listings. `PurgeListingsCache` drops the whole HTTP cache store (#19), which is
 * what that needs; it runs after the commit, and not at all when the save failed.
 */
final class ReviseTierHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly TierRepository $tiers,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    /**
     * @throws TierNotFound
     * @throws TierNameTaken
     */
    public function __invoke(ReviseTier $command): void
    {
        $tier = $this->tiers->byCode($command->code);
        if (!self::differs($tier, $command)) {
            return;
        }

        $holder = $this->tiers->findOneBy(['name' => $command->name]);
        if (null !== $holder && $holder->code() !== $command->code) {
            throw TierNameTaken::create();
        }

        $tier->revise(
            $command->name,
            $command->priceRappen,
            $command->vatLine,
            $command->rankWeight,
            $command->photosMax,
            $command->roomsMax,
            $command->videosMax,
            $command->documentsMax,
            $command->contactVisible,
            $command->lastMinuteAllowed,
            $command->detailedReport,
            $command->eventTypesMax,
        );

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw TierNameTaken::create();
        }

        $this->commandBus->dispatch(new PurgeListingsCache(), [new DispatchAfterCurrentBusStamp()]);
    }

    private static function differs(Tier $tier, ReviseTier $command): bool
    {
        return $tier->name() !== $command->name
            || $tier->priceRappen() !== $command->priceRappen
            || $tier->vatLine() !== $command->vatLine
            || $tier->rankWeight() !== $command->rankWeight
            || $tier->photosMax() !== $command->photosMax
            || $tier->roomsMax() !== $command->roomsMax
            || $tier->videosMax() !== $command->videosMax
            || $tier->documentsMax() !== $command->documentsMax
            || $tier->contactVisible() !== $command->contactVisible
            || $tier->lastMinuteAllowed() !== $command->lastMinuteAllowed
            || $tier->detailedReport() !== $command->detailedReport
            || $tier->eventTypesMax() !== $command->eventTypesMax;
    }
}
