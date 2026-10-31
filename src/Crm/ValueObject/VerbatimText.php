<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A text that is already in its language, such as a staff member's name, given to a form where the
 * theme would otherwise look it up as a catalogue key (Cms's twin, which Crm may not use).
 */
final readonly class VerbatimText implements TranslatableInterface
{
    public function __construct(
        private string $text,
    ) {
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->text;
    }
}
