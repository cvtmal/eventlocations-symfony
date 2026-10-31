<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ValueObject\CompanyProperty;
use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\ContactKind;
use App\Crm\ValueObject\ContactProperty;
use App\Shared\Validator\CleanText;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The input rules of one inline-edited field (D10, P1), applied to the canonical value
 * (`PropertyValue::canonical()`), which is what the handler stores. The columns have no CHECK
 * beyond the length and the name, so the length rules keep a too long value from a 500.
 *
 * Every string carries CleanText, and a Regex or Email beside it has `normalizer: 'mb_scrub'`, so
 * invalid UTF-8 never reaches PCRE or egulias (`VenueContactData`). The handlers repeat what needs
 * the database: an active owner, an existing company, a free e-mail address.
 */
final readonly class PropertyConstraints
{
    /** The e-mail rule of `VenueContactData::EMAIL_SHAPE`: an ASCII local part, no separator or control character. */
    public const string EMAIL_SHAPE = '/^[\x21-\x3F\x41-\x7E]+@[^@\p{Z}\p{C}]+\.[^@\p{Z}\p{C}]+$/Du';

    /** Digits with the usual separators; at least one digit. */
    public const string PHONE_SHAPE = '/\A\+?[0-9 ()\/.\-]*[0-9][0-9 ()\/.\-]*\z/D';

    public const int NOTES_MAX = 20000;

    private function __construct()
    {
    }

    /**
     * @return list<Constraint>
     */
    public static function company(CompanyProperty $property): array
    {
        return match ($property) {
            CompanyProperty::Name => self::text(150, required: true),
            CompanyProperty::LegalName => self::text(200),
            CompanyProperty::Website => self::website(),
            CompanyProperty::Phone => self::phone(),
            CompanyProperty::Email => self::email(),
            CompanyProperty::Address => self::text(300),
            CompanyProperty::Stage => self::choice(array_map(static fn (CompanyStage $stage): string => $stage->value, CompanyStage::cases())),
            CompanyProperty::Owner => self::reference(),
            CompanyProperty::Notes => self::text(self::NOTES_MAX),
        };
    }

    /**
     * @return list<Constraint>
     */
    public static function contact(ContactProperty $property): array
    {
        return match ($property) {
            ContactProperty::FirstName => self::text(150),
            ContactProperty::LastName => self::text(150, required: true),
            ContactProperty::Email => self::email(),
            ContactProperty::Phone => self::phone(),
            ContactProperty::Position => self::text(150),
            ContactProperty::Kind => self::choice(array_map(static fn (ContactKind $kind): string => $kind->value, ContactKind::cases())),
            ContactProperty::Company, ContactProperty::Owner => self::reference(),
        };
    }

    /**
     * @param positive-int $max
     *
     * @return list<Constraint>
     */
    private static function text(int $max, bool $required = false): array
    {
        return [
            ...($required ? [new Assert\NotNull(message: 'crm.property.required')] : []),
            new Assert\Type('string'),
            new CleanText(),
            new Assert\Length(max: $max, maxMessage: 'crm.property.too_long'),
        ];
    }

    /**
     * @return list<Constraint>
     */
    private static function website(): array
    {
        return [
            ...self::text(500),
            new Assert\Regex(pattern: '/\Ahttps?:\/\/\S+\z/', message: 'crm.property.website', normalizer: 'mb_scrub'),
        ];
    }

    /**
     * @return list<Constraint>
     */
    private static function phone(): array
    {
        return [
            ...self::text(40),
            new Assert\Regex(pattern: self::PHONE_SHAPE, message: 'crm.property.phone', normalizer: 'mb_scrub'),
        ];
    }

    /**
     * @return list<Constraint>
     */
    private static function email(): array
    {
        return [
            ...self::text(254),
            new Assert\Sequentially(constraints: [
                new Assert\Email(message: 'crm.property.email', mode: Assert\Email::VALIDATION_MODE_STRICT, normalizer: 'mb_scrub'),
                new Assert\Regex(pattern: self::EMAIL_SHAPE, message: 'crm.property.email', normalizer: 'mb_scrub'),
            ]),
        ];
    }

    /**
     * @param list<string> $codes
     *
     * @return list<Constraint>
     */
    private static function choice(array $codes): array
    {
        return [
            new Assert\NotNull(message: 'crm.property.required'),
            new Assert\Type('string'),
            new Assert\Choice(choices: $codes, message: 'crm.property.choice'),
        ];
    }

    /**
     * An id of a company or a staff account; the handler checks that it names one.
     *
     * @return list<Constraint>
     */
    private static function reference(): array
    {
        return [
            new Assert\Type('string'),
            new Assert\Uuid(message: 'crm.property.choice', normalizer: 'mb_scrub'),
        ];
    }
}
