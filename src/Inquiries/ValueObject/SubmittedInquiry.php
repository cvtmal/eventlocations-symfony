<?php

declare(strict_types=1);

namespace App\Inquiries\ValueObject;

/**
 * What `SubmitInquiry` tells the controller: the row to deliver, and whether it is a repeat of a
 * row that already existed. Only a new row is published.
 */
final readonly class SubmittedInquiry
{
    public function __construct(
        public string $id,
        public bool $isRepeat,
    ) {
    }
}
