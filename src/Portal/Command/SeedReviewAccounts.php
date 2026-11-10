<?php

declare(strict_types=1);

namespace App\Portal\Command;

/**
 * review-demo: writes the two demo accounts of the review stack (`app:review:seed-accounts`), the back-office admin and
 * the /konto manager behind ReviewMode::LOGIN. Its handler refuses outside review mode.
 */
final readonly class SeedReviewAccounts
{
}
