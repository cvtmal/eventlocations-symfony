<?php

declare(strict_types=1);

namespace App\Portal\ValueObject;

/**
 * The one correction the monthly report suggests (G10): the first case, in declaration order, that applies to the
 * entry. Each case has a sentence and a button (`report.mail.suggestion.<value>.text|button`) to the `/konto` tab
 * that fixes it; `Complete` has no button.
 */
enum ReportSuggestion: string
{
    case InquiryEmail = 'inquiry_email';
    case TitlePhoto = 'title_photo';
    case MorePhotos = 'more_photos';
    case Description = 'description';
    case Room = 'room';
    case Capacity = 'capacity';
    case EventTypes = 'event_types';
    case PriceLevel = 'price_level';
    case FreePhotoSlots = 'free_photo_slots';
    case Complete = 'complete';

    /** Below this many gallery photos, the report asks for more (while the plan has room). */
    public const int MIN_PHOTOS = 3;

    public static function for(ReportGaps $g): self
    {
        return match (true) {
            !$g->hasInquiryEmail => self::InquiryEmail,
            0 === $g->galleryPhotos => self::TitlePhoto,
            $g->galleryPhotos < self::MIN_PHOTOS && $g->photosUsed < $g->photosMax => self::MorePhotos,
            !$g->hasDescription => self::Description,
            0 === $g->rooms => self::Room,
            !$g->hasSeatedOrStanding => self::Capacity,
            0 === $g->eventTypes => self::EventTypes,
            !$g->hasPriceLevel => self::PriceLevel,
            $g->photosUsed < $g->photosMax => self::FreePhotoSlots,
            default => self::Complete,
        };
    }

    /**
     * The `/konto` page that fixes it; it takes the venue number.
     */
    public function route(): string
    {
        return match ($this) {
            self::TitlePhoto, self::MorePhotos, self::FreePhotoSlots => 'portal_location_photos',
            self::Room => 'portal_location_rooms',
            default => 'portal_location',
        };
    }

    /**
     * The card on `portal_location`; price level, seated/standing and event types sit on the event card.
     */
    public function anchor(): ?string
    {
        return match ($this) {
            self::InquiryEmail => LocationCard::Contact->anchor(),
            self::Description => LocationCard::Texts->anchor(),
            self::Capacity, self::EventTypes, self::PriceLevel => LocationCard::Event->anchor(),
            default => null,
        };
    }

    public function hasButton(): bool
    {
        return self::Complete !== $this;
    }
}
