<?php

declare(strict_types=1);

namespace App\Admin\Twig;

use Symfony\Component\Asset\Packages;
use Twig\Attribute\AsTwigFunction;

/**
 * `{{ admin_icon('users') }}` renders one symbol of `assets/icons/admin-sprite.svg`, a separate file so
 * public pages never download back-office icons. Without a label the icon is decorative.
 */
final class AdminIconExtension
{
    private const string SPRITE = 'icons/admin-sprite.svg';

    private ?string $spriteUrl = null;

    public function __construct(
        private readonly Packages $packages,
    ) {
    }

    #[AsTwigFunction('admin_icon', isSafe: ['html'])]
    public function icon(string $name, ?string $label = null): string
    {
        if (1 !== preg_match('/^[a-z0-9-]+$/', $name)) {
            throw new \InvalidArgumentException(\sprintf('Invalid icon name "%s": use the lowercase id of a symbol in %s.', $name, self::SPRITE));
        }

        $this->spriteUrl ??= $this->packages->getUrl(self::SPRITE);
        $a11y = null === $label
            ? 'aria-hidden="true"'
            : \sprintf('role="img" aria-label="%s"', htmlspecialchars($label, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'));

        return \sprintf('<svg class="icon" %s><use href="%s#%s"></use></svg>', $a11y, htmlspecialchars($this->spriteUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'), $name);
    }
}
