<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a complaint of the public form is about (G2): the rights to photos, the removal of the whole entry, or a
 * correction of its facts. Stored in `moderation_cases.kind` (`chk_moderation_cases_kind`). Only a rights
 * complaint names photos, and only those are hidden at once (`ReportContent`). The public label is
 * `report.kind.<value>` in `venues`; staff read `moderation.kind.<value>` in `cms`.
 */
enum ModerationKind: string implements TranslatableInterface
{
    case Rights = 'rights';
    case Removal = 'removal';
    case Correction = 'correction';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('report.kind.'.$this->value, [], 'venues', $locale);
    }

    public function namesPhotos(): bool
    {
        return self::Rights === $this;
    }
}
