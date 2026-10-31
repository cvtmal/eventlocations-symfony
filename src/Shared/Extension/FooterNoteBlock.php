<?php

declare(strict_types=1);

namespace App\Shared\Extension;

use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A short note in the footer of every public page (#26), read from the snapshot only, so
 * error pages stay query-free. Nothing is shown until staff write one.
 */
final class FooterNoteBlock implements ContentBlockDefinition
{
    public const string KEY = 'footer.note';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('blocks.footer_note.label', [], 'shared');
    }

    public function placement(): TranslatableInterface
    {
        return new TranslatableMessage('blocks.footer_note.placement', [], 'shared');
    }

    public function maxLength(): int
    {
        return 300;
    }

    public function fallback(): ?TranslatableInterface
    {
        return null;
    }
}
