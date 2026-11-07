<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * The declaration text in force (G1) and the version it stands for. The version is content-addressed
 * (the first 12 hex characters of the text's SHA-256), so editing the text always makes a new
 * version and nobody has to bump a number.
 */
final readonly class RightsPolicyText
{
    public function __construct(
        public string $version,
        public string $markdown,
    ) {
    }

    public static function of(string $markdown): self
    {
        return new self(substr(hash('sha256', $markdown), 0, 12), $markdown);
    }
}
