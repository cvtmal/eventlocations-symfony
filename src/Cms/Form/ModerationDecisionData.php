<?php

declare(strict_types=1);

namespace App\Cms\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Staff's optional note on a complaint's decision (G2). It stays on the case and never reaches the complainant.
 */
final class ModerationDecisionData
{
    public const int NOTE_MAX = 2000;

    #[Assert\Length(max: self::NOTE_MAX, maxMessage: 'cms.moderation.note_too_long')]
    public ?string $note = null;
}
