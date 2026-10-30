<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

/**
 * The newest audit row of a venue (CMS-11's "Zuletzt bearbeitet von … am …"). `name` is the staff member's
 * display name, null when the console or the system wrote the row.
 */
final readonly class VenueLastEditor
{
    public function __construct(
        public ?string $name,
        public \DateTimeImmutable $at,
    ) {
    }
}
