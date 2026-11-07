<?php

declare(strict_types=1);

namespace App\Venues\Exception;

use App\Shared\Exception\DomainException;

/**
 * Staff acted on a complaint that is already decided (G2): a double click or a second tab. A decision is final.
 */
final class ModerationCaseAlreadyDecided extends DomainException
{
    public static function forId(string $id): self
    {
        return new self(\sprintf('The moderation case "%s" is already decided.', $id));
    }
}
