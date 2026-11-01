<?php

declare(strict_types=1);

namespace App\Search\Extension;

use App\Shared\Extension\ContentBlockDefinition;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The long text of the home page, above the regions (#36, HP-6). Until staff write one, the
 * template shows the catalogue's two paragraphs, which is why there is no fallback here: a
 * declared fallback is a single paragraph.
 */
final class HomeTextBlock implements ContentBlockDefinition
{
    public const string KEY = 'home.text';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('blocks.home_text.label', [], 'search');
    }

    public function placement(): TranslatableInterface
    {
        return new TranslatableMessage('blocks.home_text.placement', [], 'search');
    }

    public function maxLength(): int
    {
        return 1500;
    }

    public function fallback(): ?TranslatableInterface
    {
        return null;
    }
}
