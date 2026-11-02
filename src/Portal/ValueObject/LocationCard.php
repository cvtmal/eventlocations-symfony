<?php

declare(strict_types=1);

namespace App\Portal\ValueObject;

use App\Cms\Form\VenueAddressData;
use App\Cms\Form\VenueAddressType;
use App\Cms\Form\VenueCardData;
use App\Cms\Form\VenueCardType;
use App\Cms\Form\VenueContactData;
use App\Cms\Form\VenueContactType;
use App\Cms\Form\VenueFaqData;
use App\Cms\Form\VenueFaqType;
use App\Cms\Form\VenueTermsData;
use App\Cms\Form\VenueTermsType;
use App\Cms\Form\VenueTextsData;
use App\Cms\Form\VenueTextsType;
use App\Cms\Form\VenueWeddingData;
use App\Cms\Form\VenueWeddingType;
use App\Portal\Form\VenueCapacityData;
use App\Portal\Form\VenueCapacityType;

/**
 * The cards a manager edits, in page order (POR-8). `Cms\ValueObject\VenueCard` is the staff list: this one has
 * no `basics` and no `rating`, so no route exists for them. Six cards reuse staff's form, data class and
 * template; `Capacity` is the manager's own, the maximum alone.
 */
enum LocationCard: string
{
    case Texts = 'texts';
    case Address = 'address';
    case Terms = 'terms';
    case Wedding = 'wedding';
    case Contact = 'contact';
    case Faq = 'faq';
    case Capacity = 'capacity';

    /**
     * The POST route.
     */
    public function route(): string
    {
        return 'portal_location_'.$this->value;
    }

    /**
     * The page a stale card's reload link goes to.
     */
    public function editRoute(): string
    {
        return 'portal_location';
    }

    public function anchor(): string
    {
        return match ($this) {
            self::Texts => 'texte',
            self::Address => 'adresse',
            self::Terms => 'merkmale',
            self::Wedding => 'hochzeit',
            self::Contact => 'kontakt',
            self::Faq => 'faq',
            self::Capacity => 'gaeste',
        };
    }

    /**
     * The form's name, and so the prefix of its fields.
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
            self::Texts => VenueTextsType::class,
            self::Address => VenueAddressType::class,
            self::Terms => VenueTermsType::class,
            self::Wedding => VenueWeddingType::class,
            self::Contact => VenueContactType::class,
            self::Faq => VenueFaqType::class,
            self::Capacity => VenueCapacityType::class,
        };
    }

    /**
     * @return class-string<VenueCardData>
     */
    public function dataClass(): string
    {
        return match ($this) {
            self::Texts => VenueTextsData::class,
            self::Address => VenueAddressData::class,
            self::Terms => VenueTermsData::class,
            self::Wedding => VenueWeddingData::class,
            self::Contact => VenueContactData::class,
            self::Faq => VenueFaqData::class,
            self::Capacity => VenueCapacityData::class,
        };
    }

    public function template(): string
    {
        return self::Capacity === $this
            ? 'portal/location/_card_capacity.html.twig'
            : 'admin/cms/venue/_card_'.$this->value.'.html.twig';
    }
}
