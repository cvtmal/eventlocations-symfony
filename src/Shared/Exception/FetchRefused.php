<?php

declare(strict_types=1);

namespace App\Shared\Exception;

use App\Shared\Http\PlainText;
use App\Shared\Http\RefusalReason;

/**
 * SafeFetcher will not fetch this URL, or stopped fetching it. Not an infrastructure failure: the caller reports
 * it (reason and detail) and moves on. The detail never holds a credential, because a URL with credentials is
 * refused before anything is printed.
 */
final class FetchRefused extends DomainException
{
    public readonly string $detail;

    private function __construct(
        public readonly RefusalReason $reason,
        string $detail,
        public readonly ?int $statusCode = null,
        ?\Throwable $previous = null,
    ) {
        // The detail may quote the server (a content type, an error): printable text only, 300 characters at most.
        $this->detail = PlainText::clean($detail, 300);
        parent::__construct($reason->value.': '.$this->detail, 0, $previous);
    }

    public static function because(RefusalReason $reason, string $detail, ?\Throwable $previous = null): self
    {
        return new self($reason, $detail, null, $previous);
    }

    public static function status(int $statusCode): self
    {
        return new self(RefusalReason::HttpStatus, 'the server answered '.$statusCode, $statusCode);
    }
}
