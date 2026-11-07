<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

/**
 * One preview link of an entry (G5), without its token (`Venues\PreviewToken::forId($id)` builds it).
 */
final readonly class VenuePreviewLink
{
    public function __construct(
        public string $id,
        public string $venueId,
        public int $venueNumber,
        public string $campaignCode,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
        public ?\DateTimeImmutable $revokedAt,
    ) {
    }

    /**
     * Whether the link still shows the entry at `$now`: not revoked and not yet expired.
     */
    public function isOpen(\DateTimeImmutable $now): bool
    {
        return null === $this->revokedAt && $now < $this->expiresAt;
    }

    /**
     * `open`, `expired` or `revoked` (a revoked link that also expired stays `revoked`).
     */
    public function state(\DateTimeImmutable $now): string
    {
        return match (true) {
            null !== $this->revokedAt => 'revoked',
            $now >= $this->expiresAt => 'expired',
            default => 'open',
        };
    }
}
