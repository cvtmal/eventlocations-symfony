<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * `Fetched` is the current import of a URL; a later import of the same URL turns it into `Superseded`, and keeps
 * it, so what staff saw once stays on record.
 */
enum SourceStatus: string
{
    case Fetched = 'fetched';
    case Superseded = 'superseded';
}
