<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

use App\Media\ValueObject\Picture;
use App\Venues\ValueObject\TierLabel;
use App\Venues\ValueObject\VenuePlace;
use App\Venues\ValueObject\VenueStatus;

/**
 * Everything `templates/admin/cms/venue/_layout.html.twig` shows above a venue's tabs, so #25's pages pass
 * this one object (P20). `slug` is the default locale's, null without that translation; `cover` is the first
 * gallery photo (`room_id IS NULL`), with an empty alt because the name stands next to it.
 */
final readonly class VenueHeader
{
    public function __construct(
        public int $number,
        public string $id,
        public string $name,
        public TierLabel $tier,
        public VenueStatus $status,
        public bool $isPublished,
        public VenuePlace $place,
        public ?string $street,
        public ?string $zip,
        public ?string $slug,
        public ?string $contentKey,
        public ?\DateTimeImmutable $cmsEditedAt,
        public \DateTimeImmutable $updatedAt,
        public ?VenueLastEditor $lastEditor,
        public ?Picture $cover,
        /** G12: staff checked the facts (`venues.facts_checked_at`). */
        public bool $factsChecked = false,
    ) {
    }
}
