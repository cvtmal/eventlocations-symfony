<?php

declare(strict_types=1);

namespace App\Crm;

use App\Admin\Extension\TimelineActivityTypeRegistry;
use App\Crm\Entity\Activity;
use App\Crm\Exception\ActivityNotFound;
use App\Crm\Exception\ActivityNotYours;
use App\Crm\Exception\ActivityReadOnly;
use App\Crm\Repository\ActivityRepository;

/**
 * The write side of D14, the handlers' twin of `TimelineEntry::canBeEditedBy()`, which decides what the island
 * offers: your own entry, of a kind staff write, not anonymised. `ActivityCommandsTest` holds the two together.
 * A foreign entry is refused for `admin` too.
 */
final class OwnActivity
{
    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly TimelineActivityTypeRegistry $types,
    ) {
    }

    /**
     * @throws ActivityNotFound
     * @throws ActivityReadOnly
     * @throws ActivityNotYours
     */
    public function lock(string $id, string $actingUserId): Activity
    {
        $activity = $this->activities->lock($id);
        $actor = $activity->actor();

        if (null === $actor || $activity->isAnonymised() || !$this->types->isComposable($activity->type()->code())) {
            throw ActivityReadOnly::forId($id);
        }
        if ($actor->id() !== $actingUserId) {
            throw ActivityNotYours::forId($id);
        }

        return $activity;
    }
}
