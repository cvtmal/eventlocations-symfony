<?php

declare(strict_types=1);

namespace App\Cms\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A text that is already in its language, such as a landing page's computed default title, given to a form
 * where the theme would otherwise look it up as a catalogue key (`placeholder` goes through `trans`).
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
