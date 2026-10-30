<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Who wrote a review, gendered so the German label reads naturally ("Besucherin",
 * "Veranstalter"). Mirrors eventlokale's visitor_f/m and owner_f/m. Stored in
 * `venue_reviews.role` (chk_venue_reviews_role).
 */
enum ReviewerRole: string implements TranslatableInterface
{
    case VisitorF = 'visitor_f';
    case VisitorM = 'visitor_m';
    case OrganiserF = 'organiser_f';
    case OrganiserM = 'organiser_m';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('reviewer_role.'.$this->value, [], 'venues', $locale);
    }
}
