<?php

declare(strict_types=1);

namespace App\Inquiries\ReadModel;

/**
 * A venue the brief page names. `offered` is false when the venue has no inquiry address, opted out of briefs or
 * reached today's cap: it is then never a choice of the brief, only a link to its page, to its inquiry form when it is
 * `inquirable` (has an inquiry address).
 */
final readonly class BriefCandidate
{
    public function __construct(
        public string $id,
        public int $number,
        public string $name,
        public string $slug,
        public ?string $town,
        public bool $offered,
        public bool $inquirable,
    ) {
    }
}
