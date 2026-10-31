<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * Where the next timeline page starts: strictly older than this `(time, id)` pair. Both sources of the timeline,
 * `crm_activities` and `admin_audit_log`, have UUID ids and whole-second times, so one pair orders the merge.
 * It travels as `?before=<time>_<id>`; anything that does not parse is no cursor (the first page).
 */
final readonly class TimelineCursor
{
    public function __construct(
        public \DateTimeImmutable $at,
        public string $id,
    ) {
    }

    public static function parse(?string $token): ?self
    {
        if (null === $token || 1 !== preg_match('/\A(-?\d{1,10})_([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\z/', $token, $match)) {
            return null;
        }

        return new self(new \DateTimeImmutable('@'.$match[1]), $match[2]);
    }

    public function token(): string
    {
        return $this->at->getTimestamp().'_'.$this->id;
    }
}
