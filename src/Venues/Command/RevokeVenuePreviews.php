<?php

declare(strict_types=1);

namespace App\Venues\Command;

/**
 * G6: ends every open preview link of a venue once somebody manages it, because the banner "Noch nicht vom Betreiber
 * bestätigt" is no longer true. Nested in the commands that grant a manager access (a claim, staff's approval), whose
 * own audit row covers it. Answers how many links it ended.
 */
final readonly class RevokeVenuePreviews
{
    public function __construct(
        public string $venueId,
    ) {
    }
}
