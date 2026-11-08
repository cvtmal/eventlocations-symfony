<?php

declare(strict_types=1);

namespace App\Venues\Form;

use App\Shared\Validator\CleanText;
use App\Venues\Command\ReportContent;
use App\Venues\ValueObject\ModerationKind;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The shape of a complaint (G2, the form layer of the validation strategy); messages are `report.*` in
 * `validators`. Every text runs `CleanText` first, in sequence: a NUL or invalid UTF-8 is refused before any rule
 * that hands the value to an ICU message (which throws on invalid UTF-8).
 *
 * The e-mail rule is the inquiry form's (strict mode, then a printable ASCII local part and no separator or control
 * character), copied because Venues may not use Inquiries: it refuses more than `chk_moderation_cases_email`, never
 * less. `venueReference` is required only when the form shows no venue (`hasVenue`, set by the controller).
 * `source` is the page the form was opened from, a same-site path (`chk_moderation_cases_source_url`).
 */
final class ReportContentData
{
    public const string EMAIL_SHAPE = '/^[\x21-\x3F\x41-\x7E]+@[^@\p{Z}\p{C}]+$/Du';

    /** "/" alone or "/x…": never "//host", a backslash or white space, which a browser could read as another site. */
    public const string SOURCE_SHAPE = '#^/(?:[^/\\\\\s][^\s]*)?$#D';

    public const int MESSAGE_MIN = 10;
    public const int MESSAGE_MAX = 5000;

    #[Assert\NotNull(message: 'report.kind.required')]
    public ?ModerationKind $kind = null;

    /** @var list<string> the photo ids a rights complaint names (the form offers the public ones only) */
    #[Assert\Count(max: ReportContent::MAX_PHOTOS, maxMessage: 'report.photos.too_many')]
    public array $photos = [];

    #[Assert\Sequentially([
        new CleanText(),
        new Assert\Length(max: 500, maxMessage: 'report.venue.too_long'),
        new Assert\Regex(pattern: '/[\x00-\x1F\x7F]/', message: 'report.venue.invalid', match: false),
    ])]
    public ?string $venueReference = null;

    #[Assert\Sequentially([
        new CleanText(),
        new Assert\NotBlank(message: 'report.message.required'),
        new Assert\Length(min: self::MESSAGE_MIN, max: self::MESSAGE_MAX, minMessage: 'report.message.too_short', maxMessage: 'report.message.too_long'),
    ])]
    public ?string $message = null;

    #[Assert\Sequentially([
        new CleanText(),
        new Assert\NotBlank(message: 'report.name.required'),
        new Assert\Length(max: 100, maxMessage: 'report.name.too_long'),
        new Assert\Regex(pattern: '/[\x00-\x1F\x7F]/', message: 'report.name.invalid', match: false),
    ])]
    public ?string $name = null;

    #[Assert\Sequentially([
        new CleanText(),
        new Assert\NotBlank(message: 'report.email.required'),
        new Assert\Length(max: 254, maxMessage: 'report.email.too_long'),
        new Assert\Email(message: 'report.email.invalid', mode: Assert\Email::VALIDATION_MODE_STRICT),
        new Assert\Regex(pattern: self::EMAIL_SHAPE, message: 'report.email.invalid'),
    ])]
    public ?string $email = null;

    #[Assert\IsTrue(message: 'report.good_faith.required')]
    public bool $goodFaith = false;

    #[Assert\Sequentially([
        new CleanText(),
        new Assert\Length(max: 500),
        new Assert\Regex(pattern: self::SOURCE_SHAPE),
    ])]
    public ?string $source = null;

    /** Not a field: whether the form shows a venue of the site, so the free-text venue is not asked for. */
    public bool $hasVenue = false;

    #[Assert\Callback]
    public function validateVenue(ExecutionContextInterface $context): void
    {
        if (!$this->hasVenue && (null === $this->venueReference || '' === trim($this->venueReference))) {
            $context->buildViolation('report.venue.required')->atPath('venueReference')->addViolation();
        }
    }
}
