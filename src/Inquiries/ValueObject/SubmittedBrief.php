<?php

declare(strict_types=1);

namespace App\Inquiries\ValueObject;

/**
 * What `SubmitInquiryBrief` tells the controller: the brief, its children to deliver in the guest's order, and
 * whether it repeats a brief that already existed. Only a new brief's children are published.
 */
final readonly class SubmittedBrief
{
    /**
     * @param non-empty-list<string> $inquiryIds
     */
    public function __construct(
        public string $id,
        public array $inquiryIds,
        public bool $isRepeat,
    ) {
    }
}
