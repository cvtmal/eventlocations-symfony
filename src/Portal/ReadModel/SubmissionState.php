<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use App\Portal\ValueObject\SubmissionStatus;

final readonly class SubmissionState
{
    public function __construct(
        public SubmissionStatus $status,
        public ?string $rejectReason,
    ) {
    }
}
