<?php

declare(strict_types=1);

namespace App\Cms\ValueObject;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * A flash of the Cms screens. `_flash.html.twig` translates with the `admin` domain, but a
 * `TranslatableMessage` keeps its own domain and parameters through the session (Probe 4), so CMS strings
 * stay in `cms` (D23).
 */
final readonly class CmsFlash
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function message(string $key, array $parameters = []): TranslatableMessage
    {
        return new TranslatableMessage($key, $parameters, 'cms');
    }
}
