<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

/**
 * A published venue as the complaint form shows it (G2): its name, the slug of the way back, and the photos the
 * public sees right now, which a rights complaint may name.
 */
final readonly class ReportableVenue
{
    /**
     * @param list<ReportablePhoto> $photos gallery first, then the rooms, in their order
     */
    public function __construct(
        public string $id,
        public int $number,
        public string $name,
        public string $slug,
        public array $photos,
    ) {
    }

    /**
     * @return list<string>
     */
    public function photoIds(): array
    {
        return array_map(static fn (ReportablePhoto $photo): string => $photo->picture->photoId, $this->photos);
    }
}
