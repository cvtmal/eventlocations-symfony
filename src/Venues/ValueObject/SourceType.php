<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * Where the facts of a source record came from. Only the venue's own website so far (G3); the PRD's other
 * sources (an owner upload, a licensed library) add a case and a migration when their cycle needs one.
 */
enum SourceType: string
{
    case OfficialWebsite = 'official_website';
}
