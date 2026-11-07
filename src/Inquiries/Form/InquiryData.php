<?php

declare(strict_types=1);

namespace App\Inquiries\Form;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The shape of an inquiry (the form layer of the validation strategy). The message keys are
 * in the `validators` domain. The past-date rule needs the clock, so it is the handler's. Which
 * event types and catering wishes exist is the vocabulary's (`InquiryType` offers only those).
 *
 * Strict e-mail mode because the default `html5` mode accepts `a..b@x.ch`, which `Address`
 * then refuses after the row is stored. `EMAIL_SHAPE` closes what strict mode leaves open and
 * would fail after validation:
 *
 * - The local part is printable ASCII. A non-ASCII one needs SMTPUTF8, which a server may not
 *   offer, and strict mode accepts it.
 * - Neither part holds a separator or a control character: `chk_inquiries_email` uses Postgres'
 *   `\s`, which is Unicode-aware, unlike PCRE's without `/u`, so a copy of its regex does not
 *   agree with it. It also refuses `"a b"@x.ch`.
 *
 * The form may refuse more than the CHECK, never less (`InquiryEmailRuleTest`). An IDN domain
 * stays valid: `Address` encodes it. The two rules share a message, so they run in sequence and a
 * typo shows it once. Names are one line, and Postgres stores no NUL byte in any text column.
 */
final class InquiryData
{
    private const EMAIL_SHAPE = '/^[\x21-\x3F\x41-\x7E]+@[^@\p{Z}\p{C}]+$/Du';

    /** A `suitable_for` code; no default (DP-13). */
    #[Assert\NotNull(message: 'inquiry.event_type.required')]
    public ?string $eventType = null;

    #[Assert\NotNull(message: 'inquiry.guests.required')]
    #[Assert\Range(notInRangeMessage: 'inquiry.guests.range', min: 1, max: 5000)]
    public ?int $guests = null;

    public ?\DateTimeImmutable $eventDate = null;

    public bool $dateFlexible = false;

    public ?\DateTimeImmutable $startTime = null;

    /** A catering code of `InquiryVocabularyQuery::CATERING_WISHES`; null is "noch offen". */
    public ?string $cateringWish = null;

    #[Assert\Range(notInRangeMessage: 'inquiry.budget.range', min: 1, max: 1_000_000)]
    public ?int $budgetChf = null;

    #[Assert\NotBlank(message: 'inquiry.message.required')]
    #[Assert\Length(max: 5000, maxMessage: 'inquiry.message.too_long')]
    #[Assert\Regex(pattern: '/\x00/', message: 'inquiry.message.invalid', match: false)]
    public ?string $message = null;

    #[Assert\NotBlank(message: 'inquiry.first_name.required')]
    #[Assert\Length(max: 100, maxMessage: 'inquiry.first_name.too_long')]
    #[Assert\Regex(pattern: '/[\x00-\x1F\x7F]/', message: 'inquiry.first_name.invalid', match: false)]
    public ?string $firstName = null;

    #[Assert\NotBlank(message: 'inquiry.last_name.required')]
    #[Assert\Length(max: 100, maxMessage: 'inquiry.last_name.too_long')]
    #[Assert\Regex(pattern: '/[\x00-\x1F\x7F]/', message: 'inquiry.last_name.invalid', match: false)]
    public ?string $lastName = null;

    #[Assert\Length(max: 150, maxMessage: 'inquiry.company.too_long')]
    #[Assert\Regex(pattern: '/[\x00-\x1F\x7F]/', message: 'inquiry.company.invalid', match: false)]
    public ?string $company = null;

    #[Assert\NotBlank(message: 'inquiry.email.required')]
    #[Assert\Sequentially(constraints: [
        new Assert\Email(message: 'inquiry.email.invalid', mode: Assert\Email::VALIDATION_MODE_STRICT),
        new Assert\Regex(pattern: self::EMAIL_SHAPE, message: 'inquiry.email.invalid'),
    ])]
    #[Assert\Length(max: 254, maxMessage: 'inquiry.email.too_long')]
    public ?string $email = null;

    #[Assert\Length(max: 40, maxMessage: 'inquiry.phone.invalid')]
    #[Assert\Regex(pattern: '/^[0-9 +()\/.-]{6,40}$/D', message: 'inquiry.phone.invalid')]
    public ?string $phone = null;

    #[Assert\Callback]
    public function validateDate(ExecutionContextInterface $context): void
    {
        if (null === $this->eventDate && !$this->dateFlexible) {
            $context->buildViolation('inquiry.event_date.required')->atPath('eventDate')->addViolation();
        }
    }
}
