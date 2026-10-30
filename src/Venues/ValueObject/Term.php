<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One taxonomy term, identified by its group and its language-neutral code (a row of
 * `taxonomy_terms`). Its label is `term.<group>.<code>` in the `venues` catalogue.
 */
final readonly class Term implements TranslatableInterface
{
    public function __construct(
        public TermGroup $group,
        public string $code,
    ) {
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('term.'.$this->group->value.'.'.$this->code, [], 'venues', $locale);
    }
}
