<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\PurgeListingsCache;
use App\Venues\HttpCachePurger;

/**
 * Dispatched with DispatchAfterCurrentBusStamp: runs after the dispatching command's
 * transaction committed, and not at all when that command failed.
 */
final class PurgeListingsCacheHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly HttpCachePurger $purger,
    ) {
    }

    public function __invoke(PurgeListingsCache $command): void
    {
        $this->purger->purgeListings();
    }
}
