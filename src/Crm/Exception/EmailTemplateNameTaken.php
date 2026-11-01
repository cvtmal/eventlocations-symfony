<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * Another mail template has this name (uk_crm_email_templates_name, case-sensitive, D10). The message never quotes
 * the name. The template form shows it on its `name` field.
 */
final class EmailTemplateNameTaken extends DomainException
{
    private function __construct()
    {
        parent::__construct('Another mail template has this name.');
    }

    public static function create(): self
    {
        return new self();
    }
}
