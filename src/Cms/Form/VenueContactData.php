<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\VenueEdit;
use App\Venues\Command\ChangeVenueContact;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Kontakt (D17, D18, P16). Each rule may refuse more than its CHECK, never less (`VenueContactRulesTest`).
 *
 * The inquiry address repeats `InquiryData`'s shape (`Cms` may not use `Inquiries`) with the dot in the
 * domain that `chk_venues_inquiry_email` asks for: an ASCII local part, which delivery needs without
 * SMTPUTF8, and no Unicode separator or control character, because Postgres' `\s` is Unicode-aware and
 * PCRE's without `/u` is not. The phone arrives as E.164 from `PhoneNumberTransformer`.
 */
final class VenueContactData implements VenueCardData
{
    use CarriesLoadedAt;

    public const string EMAIL_SHAPE = '/^[\x21-\x3F\x41-\x7E]+@[^@\p{Z}\p{C}]+\.[^@\p{Z}\p{C}]+$/Du';

    public ?string $phone = null;

    #[Assert\Sequentially(constraints: [
        new Assert\Email(message: 'cms.venue.email.invalid', mode: Assert\Email::VALIDATION_MODE_STRICT, normalizer: 'mb_scrub'),
        new Assert\Regex(pattern: self::EMAIL_SHAPE, message: 'cms.venue.email.invalid', normalizer: 'mb_scrub'),
    ])]
    #[Assert\Length(max: 254, maxMessage: 'cms.venue.too_long')]
    public ?string $inquiryEmail = null;

    #[Assert\Length(max: 500, maxMessage: 'cms.venue.too_long')]
    #[Assert\Regex(pattern: '/\Ahttps?:\/\//', message: 'cms.venue.website.scheme', normalizer: 'mb_scrub')]
    public ?string $website = null;

    #[Assert\Length(max: 150, maxMessage: 'cms.venue.too_long')]
    public ?string $contactPerson = null;

    #[Assert\Length(max: 500, maxMessage: 'cms.venue.too_long')]
    #[Assert\Regex(pattern: '/\Ahttps:\/\//', message: 'cms.venue.instagram.scheme', normalizer: 'mb_scrub')]
    public ?string $instagram = null;

    public static function from(VenueEdit $edit): static
    {
        $data = new self();
        $data->loadedAt = $edit->loadedAt;
        $data->phone = $edit->phone;
        $data->inquiryEmail = $edit->inquiryEmail;
        $data->website = $edit->website;
        $data->contactPerson = $edit->contactPerson;
        $data->instagram = $edit->instagram;

        return $data;
    }

    public function toCommand(int $venueNumber, VenueEdit $edit, array $changedFields): ChangeVenueContact
    {
        return new ChangeVenueContact(
            $venueNumber,
            $this->token(),
            $changedFields,
            $this->phone,
            $this->inquiryEmail,
            $this->website,
            $this->contactPerson,
            $this->instagram,
        );
    }
}
