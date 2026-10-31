<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ValueObject\LandingKey;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A landing page's three texts in one locale (#26). Every field is optional: a blank one keeps (or brings
 * back) #17's text. The limits count code points of the trimmed text, as `char-counter.js` and
 * `ContentTooLong` do; `AdminTextConstraintExtension` adds the NUL and UTF-8 rule.
 */
final class LandingLocaleTextsData
{
    #[Assert\Length(max: LandingKey::INTRO_MAX_LENGTH, maxMessage: 'cms.landing.too_long', normalizer: 'trim')]
    public ?string $intro = null;

    #[Assert\Length(max: LandingKey::TITLE_MAX_LENGTH, maxMessage: 'cms.landing.too_long', normalizer: 'trim')]
    public ?string $title = null;

    #[Assert\Length(max: LandingKey::DESCRIPTION_MAX_LENGTH, maxMessage: 'cms.landing.too_long', normalizer: 'trim')]
    public ?string $description = null;
}
