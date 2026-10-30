<?php

declare(strict_types=1);

namespace App\Cms\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The stale token of a card (D12): `EXTRACT(EPOCH FROM updated_at)::bigint` as loaded. It travels in a
 * `HiddenType`, which `AdminTextConstraintExtension` does not reach, so the shape is checked here and a
 * tampered value is a 422 before it reaches `VenueEditing::open()`.
 */
trait CarriesLoadedAt
{
    #[Assert\NotBlank(message: 'cms.venue.loaded_at.invalid')]
    #[Assert\Regex(pattern: '/\A\d{1,12}\z/', message: 'cms.venue.loaded_at.invalid', normalizer: 'mb_scrub')]
    public ?string $loadedAt = null;

    private function token(): string
    {
        return (string) $this->loadedAt;
    }
}
