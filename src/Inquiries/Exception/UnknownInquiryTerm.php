<?php

declare(strict_types=1);

namespace App\Inquiries\Exception;

use App\Shared\Exception\DomainException;

/**
 * The event type or the catering wish is no code of the vocabulary the form offers (`InquiryVocabularyQuery`).
 */
final class UnknownInquiryTerm extends DomainException
{
    public static function eventType(string $code): self
    {
        return new self(\sprintf('"%s" is no event type.', $code));
    }

    public static function cateringWish(string $code): self
    {
        return new self(\sprintf('"%s" is no catering wish.', $code));
    }
}
