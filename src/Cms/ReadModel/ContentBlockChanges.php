<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

/**
 * The newest change per subject, for the back office's lists (#26). A landing page's three keys count as one
 * subject (`landing.<type>.<id>`), a block's key is its own. A subject without a row has no change.
 */
final readonly class ContentBlockChanges
{
    /**
     * @param array<string, \DateTimeImmutable> $bySubject subject → newest change, any locale
     */
    public function __construct(
        private array $bySubject,
    ) {
    }

    public function of(string $subject): ?\DateTimeImmutable
    {
        return $this->bySubject[$subject] ?? null;
    }
}
