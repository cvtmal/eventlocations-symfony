<?php

declare(strict_types=1);

namespace App\Inquiries\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "Anlass" of an inquiry (DP-13), stored in `inquiries.occasion` (chk_inquiries_occasion).
 * The case order is the form's option order; Hochzeit is the default.
 */
enum Occasion: string implements TranslatableInterface
{
    case Wedding = 'wedding';
    case CivilWedding = 'civil_wedding';
    case WeddingAperitif = 'wedding_aperitif';
    case Engagement = 'engagement';
    case Other = 'other';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('occasion.'.$this->value, [], 'inquiries', $locale);
    }
}
