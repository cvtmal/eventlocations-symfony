<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

/**
 * One PDF of the Dokumente section (DP-12); its URL is `document_url(venue.number, id)`.
 */
final readonly class DetailDocument
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }
}
