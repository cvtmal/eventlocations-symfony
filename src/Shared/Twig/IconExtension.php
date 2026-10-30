<?php

declare(strict_types=1);

namespace App\Shared\Twig;

use Symfony\Component\Asset\Packages;
use Twig\Attribute\AsTwigFunction;

/**
 * `{{ icon('heart', {label: 'Merkliste', class: 'icon--sm'}) }}` renders one symbol of
 * `assets/icons/sprite.svg` as `<svg class="icon"><use href="/assets/icons/sprite-<digest>.svg#heart"></use></svg>`.
 * Without a label the icon is decorative (`aria-hidden`); with one it is an image with an accessible name.
 */
final class IconExtension
{
    private const string SPRITE = 'icons/sprite.svg';

    private ?string $spriteUrl = null;

    public function __construct(
        private readonly Packages $packages,
    ) {
    }

    /**
     * @param array{label?: string, class?: string} $options
     */
    #[AsTwigFunction('icon', isSafe: ['html'])]
    public function icon(string $name, array $options = []): string
    {
        if (1 !== preg_match('/^[a-z0-9-]+$/', $name)) {
            throw new \InvalidArgumentException(\sprintf('Invalid icon name "%s": use the lowercase id of a symbol in %s.', $name, self::SPRITE));
        }

        $this->spriteUrl ??= $this->packages->getUrl(self::SPRITE);

        $class = trim('icon '.trim($options['class'] ?? ''));
        $label = $options['label'] ?? null;
        $a11y = null === $label
            ? 'aria-hidden="true"'
            : \sprintf('role="img" aria-label="%s"', self::escape($label));

        return \sprintf(
            '<svg class="%s" %s><use href="%s#%s"></use></svg>',
            self::escape($class),
            $a11y,
            self::escape($this->spriteUrl),
            $name,
        );
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }
}
