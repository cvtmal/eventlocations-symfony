<?php

declare(strict_types=1);

namespace App\Geo\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Attribute\AsTwigFunction;

/**
 * {{ canton_map() }} inlines assets/maps/cantons.svg (26 `<path id="ch-<code>">` plus a
 * lakes layer; source and licence in assets/maps/LICENSES.md).
 */
final class CantonMapExtension
{
    private ?string $markup = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/assets/maps/cantons.svg')]
        private readonly string $svgPath,
    ) {
    }

    #[AsTwigFunction('canton_map', isSafe: ['html'])]
    public function cantonMap(): string
    {
        if (null === $this->markup) {
            $markup = is_readable($this->svgPath) ? file_get_contents($this->svgPath) : false;
            if (false === $markup) {
                throw new \RuntimeException(\sprintf('The canton map "%s" cannot be read.', $this->svgPath));
            }
            $this->markup = $markup;
        }

        return $this->markup;
    }
}
