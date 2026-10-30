<?php

declare(strict_types=1);

namespace App\Shared\Exception;

/**
 * Base class for every business-rule violation thrown by the application.
 */
abstract class DomainException extends \RuntimeException
{
}
