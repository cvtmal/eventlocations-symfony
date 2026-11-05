<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ValueObject\Chf;
use App\Crm\ValueObject\CompanyProperty;
use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\ContactProperty;
use App\Crm\ValueObject\DealProperty;
use App\Crm\ValueObject\TaskDueDate;
use App\Shared\Validator\CleanText;
use App\Venues\ValueObject\Tier;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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

    public const int TASK_TITLE_MAX = 200;

    /** `crm_activities.title`, where a sent mail keeps its subject. */
    public const int EMAIL_SUBJECT_MAX = 200;

    /** `crm_email_templates.name`. */
    public const int EMAIL_TEMPLATE_NAME_MAX = 100;

    private function __construct()
    {
    }

    /**
     * The text of a timeline entry, as canonical as a property (CRM-4): an entry says something.
     *
     * @return list<Constraint>
     */
    public static function activityBody(): array
    {
        return self::text(self::NOTES_MAX, required: true);
    }

    /**
     * @return list<Constraint>
     */
    public static function taskTitle(): array
    {
        return self::text(self::TASK_TITLE_MAX, required: true);
    }

    /**
     * An account a new task can go to; the handler checks that it is an active one.
     *
     * @return list<Constraint>
     */
    public static function taskAssignee(): array
    {
        return [new Assert\NotNull(message: 'crm.task.assignee'), ...self::reference()];
    }

    /**
     * One line, because it becomes a mail header.
     *
     * @return list<Constraint>
     */
    public static function emailSubject(): array
    {
        return [
            ...self::text(self::EMAIL_SUBJECT_MAX, required: true),
            new Assert\Regex(pattern: '/\A[^\r\n]*\z/D', message: 'crm.email.subject_line', normalizer: 'mb_scrub'),
        ];
    }

    /**
     * @return list<Constraint>
     */
    public static function emailTemplateName(): array
    {
        return [new Assert\NotBlank(message: 'crm.property.required'), ...self::text(self::EMAIL_TEMPLATE_NAME_MAX)];
    }

    /**
     * @return list<Constraint>
     */
    public static function emailCc(): array
    {
        return self::email();
    }

    /**
     * The contact a company's mail goes to, none for the company's own address; the handler checks that it is one of
     * the company's.
     *
     * @return list<Constraint>
     */
    public static function emailContact(): array
    {
        return self::reference();
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
        };
    }

    /**
     * The rules of a deal field. Unlike a company's or contact's, an amount and a date are applied to what was typed,
     * because `DealProperty::canonical()` can only read what passed them.
     *
     * @return list<Constraint>
     */
    public static function deal(DealProperty $property): array
    {
        return match ($property) {
            DealProperty::Name => self::dealName(),
            DealProperty::Venue, DealProperty::Owner => self::reference(),
            DealProperty::Tier => self::choice(array_map(static fn (Tier $tier): string => $tier->value, Tier::cases()), required: false),
            DealProperty::Amount => self::amount(),
            DealProperty::ContractStart, DealProperty::ContractEnd, DealProperty::ExpectedClose => self::date(),
        };
    }

    /**
     * @return list<Constraint>
     */
    public static function dealName(): array
    {
        return self::text(150, required: true);
    }

    /**
     * Francs as typed, within `Chf`'s limit (N10). Empty is allowed: the field is optional.
     *
     * @return list<Constraint>
     */
    public static function amount(): array
    {
        return [
            ...self::text(30),
            new Assert\Callback(static function (mixed $value, ExecutionContextInterface $context): void {
                if (null === $value || '' === $value) {
                    return;
                }
                try {
                    Chf::parse((string) $value);
                } catch (\InvalidArgumentException) {
                    $context->buildViolation('crm.property.amount')->addViolation();
                }
            }),
        ];
    }

    /**
     * @return list<Constraint>
     */
    private static function date(): array
    {
        return [
            ...self::text(10),
            new Assert\Callback(static function (mixed $value, ExecutionContextInterface $context): void {
                if (null !== $value && null === TaskDueDate::parse($value)) {
                    $context->buildViolation('crm.property.date')->addViolation();
                }
            }),
        ];
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
    private static function choice(array $codes, bool $required = true): array
    {
        return [
            ...($required ? [new Assert\NotNull(message: 'crm.property.required')] : []),
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
