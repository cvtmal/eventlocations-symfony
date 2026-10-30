<?php

declare(strict_types=1);

namespace App\Inquiries\Event;

/**
 * An inquiry was stored and its delivery has run, so `inquiries` holds the mail outcome (#29
 * reads it there). Published by `InquiryEvents` once per row, also when the venue mail failed. A
 * repeat of the row publishes nothing, so a `failed` or `pending` row can turn `sent` later: read
 * the outcome when showing it, do not keep a copy. `pending` is possible: an unexpected error
 * before the venue mail stops the delivery with nothing sent.
 */
final readonly class InquirySubmitted
{
    public function __construct(
        public string $inquiryId,
    ) {
    }
}
