<?php

declare(strict_types=1);

namespace App\Cms\Exception;

use App\Shared\Exception\DomainException;

/**
 * Someone saved, added or cleared one of the editor's texts after the form was loaded (#26,
 * P8): its version token no longer matches the rows. Nothing was written; the editor answers
 * 409 and keeps the typed values.
 */
final class StaleContentBlocks extends DomainException
{
    private function __construct(public readonly string $subject)
    {
        parent::__construct(\sprintf('The texts of "%s" were changed after the form was loaded.', $subject));
    }

    public static function forSubject(string $subject): self
    {
        return new self($subject);
    }
}
