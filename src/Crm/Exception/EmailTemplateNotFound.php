<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * No mail template has this id (deleted, or never existed). Pages answer 404.
 */
final class EmailTemplateNotFound extends DomainException
{
    private function __construct(public readonly string $templateId)
    {
        parent::__construct(\sprintf('There is no mail template "%s".', $templateId));
    }

    public static function forId(string $templateId): self
    {
        return new self($templateId);
    }
}
