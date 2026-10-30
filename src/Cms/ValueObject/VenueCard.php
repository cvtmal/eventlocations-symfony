<?php

declare(strict_types=1);

namespace App\Cms\ValueObject;

use App\Cms\Form\VenueAddressData;
use App\Cms\Form\VenueAddressType;
use App\Cms\Form\VenueBasicsData;
use App\Cms\Form\VenueBasicsType;
use App\Cms\Form\VenueCardData;
use App\Cms\Form\VenueCardType;
use App\Cms\Form\VenueContactData;
use App\Cms\Form\VenueContactType;
use App\Cms\Form\VenueFaqData;
use App\Cms\Form\VenueFaqType;
use App\Cms\Form\VenueRatingData;
use App\Cms\Form\VenueRatingType;
use App\Cms\Form\VenueTermsData;
use App\Cms\Form\VenueTermsType;
use App\Cms\Form\VenueTextsData;
use App\Cms\Form\VenueTextsType;
use App\Cms\Form\VenueWeddingData;
use App\Cms\Form\VenueWeddingType;

/**
 * The cards of the venue editor, in page order. Each has its own form, POST route and anchor (D24), so a
 * save returns to `…/{number}#<anchor>` and a failed one re-renders that card bound and the others fresh.
 */
enum VenueCard: string
{
    case Basics = 'basics';
    case Texts = 'texts';
    case Address = 'address';
    case Terms = 'terms';
    case Wedding = 'wedding';
    case Contact = 'contact';
    case Faq = 'faq';
    case Rating = 'rating';

    /**
     * The POST route, `admin_locations_<card>` (the navigation marks every `admin_locations_…` route).
     */
    public function route(): string
    {
        return 'admin_locations_'.$this->value;
    }

    public function anchor(): string
    {
        return match ($this) {
            self::Basics => 'stammdaten',
            self::Texts => 'texte',
            self::Address => 'adresse',
            self::Terms => 'merkmale',
            self::Wedding => 'hochzeit',
            self::Contact => 'kontakt',
            self::Faq => 'faq',
            self::Rating => 'bewertung',
        };
    }

    /**
     * The form's name, and so the prefix of its fields and its CSRF token id.
     */
    public function formName(): string
    {
        return 'venue_'.$this->value;
    }

    /**
     * @return class-string<VenueCardType>
     */
    public function formType(): string
    {
        return match ($this) {
            self::Basics => VenueBasicsType::class,
            self::Texts => VenueTextsType::class,
            self::Address => VenueAddressType::class,
            self::Terms => VenueTermsType::class,
            self::Wedding => VenueWeddingType::class,
            self::Contact => VenueContactType::class,
            self::Faq => VenueFaqType::class,
            self::Rating => VenueRatingType::class,
        };
    }

    /**
     * @return class-string<VenueCardData>
     */
    public function dataClass(): string
    {
        return match ($this) {
            self::Basics => VenueBasicsData::class,
            self::Texts => VenueTextsData::class,
            self::Address => VenueAddressData::class,
            self::Terms => VenueTermsData::class,
            self::Wedding => VenueWeddingData::class,
            self::Contact => VenueContactData::class,
            self::Faq => VenueFaqData::class,
            self::Rating => VenueRatingData::class,
        };
    }
}
