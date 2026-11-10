<?php

declare(strict_types=1);

namespace App\Admin\Security;

use App\Shared\Security\ReviewMode;
use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContextInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Condition\TwoFactorConditionInterface;

/**
 * review-demo: the operator's demo (APP_REVIEW_MODE=1, which only boots on the review stack) asks for no TOTP code.
 * Everywhere else the back office keeps its mandatory 2FA.
 */
final readonly class ReviewModeTwoFactorCondition implements TwoFactorConditionInterface
{
    public function __construct(
        private ReviewMode $reviewMode,
    ) {
    }

    public function shouldPerformTwoFactorAuthentication(AuthenticationContextInterface $context): bool
    {
        return !$this->reviewMode->isOn();
    }
}
