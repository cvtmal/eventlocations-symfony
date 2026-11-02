<?php

declare(strict_types=1);

namespace App\Portal\ValueObject;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * A flash of the portal screens. `_flash.html.twig` translates with the `admin` domain, but a
 * `TranslatableMessage` keeps its own domain and parameters through the session.
 */
final readonly class PortalFlash
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function message(string $key, array $parameters = []): TranslatableMessage
    {
        return new TranslatableMessage($key, $parameters, 'portal');
    }
}
