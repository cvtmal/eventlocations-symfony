<?php

declare(strict_types=1);

namespace App\Shared\Twig;

use App\Shared\Security\ReviewMode;
use Twig\Attribute\AsTwigFunction;

/** review-demo: the login forms take the plain name `admin` in review mode (ReviewMode::LOGIN). */
final readonly class ReviewModeExtension
{
    public function __construct(
        private ReviewMode $reviewMode,
    ) {
    }

    #[AsTwigFunction('review_mode')]
    public function isOn(): bool
    {
        return $this->reviewMode->isOn();
    }
}
