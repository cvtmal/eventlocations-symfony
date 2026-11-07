<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

use App\Media\ValueObject\Picture;
use App\Venues\ValueObject\TierLabel;
use App\Venues\ValueObject\VenuePlace;
use App\Venues\ValueObject\VenueStatus;

/**
 * One row of the venue list, the dashboard widgets and the global search. `slug` is the default locale's
 * (null without that translation); `photoCount` and `thumbnail` count gallery photos only; `missing` holds
 * the `MISSING_*` codes of "unvollständig" in CMS-1's order; `editorName` is the newest audit row's staff
 * member (null for none or the console).
 */
final readonly class VenueListRow
{
    public const string MISSING_PHOTO = 'photo';
    public const string MISSING_INQUIRY_EMAIL = 'inquiry_email';
    public const string MISSING_SUMMARY = 'summary';
    public const string MISSING_MAIN_BUILDING = 'main_building';

    /**
     * @param list<string> $missing
     */
    public function __construct(
        public int $number,
        public string $name,
        public ?string $slug,
        public VenuePlace $place,
        public TierLabel $tier,
        public ?int $capacityMin,
        public int $capacityMax,
        public int $photoCount,
        public VenueStatus $status,
        public ?Picture $thumbnail,
        public ?\DateTimeImmutable $cmsEditedAt,
        public \DateTimeImmutable $updatedAt,
        public ?string $contentKey,
        public ?string $editorName,
        public array $missing,
    ) {
    }

    public function isPublished(): bool
    {
        return VenueStatus::Published === $this->status;
    }

    public function isIncomplete(): bool
    {
        return [] !== $this->missing;
    }

    /**
     * First and last word's first letters, as `AdminIdentity::initials()` (a security user, so not shared).
     */
    public function editorInitials(): ?string
    {
        if (null === $this->editorName) {
            return null;
        }

        $words = preg_split('/\s+/', trim($this->editorName)) ?: [];
        $first = $words[0] ?? '';
        $last = \count($words) > 1 ? $words[\count($words) - 1] : '';

        return mb_strtoupper(mb_substr($first, 0, 1).mb_substr($last, 0, 1));
    }
}
