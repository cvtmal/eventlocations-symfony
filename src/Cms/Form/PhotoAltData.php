<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Shared\Validator\CleanText;
use App\Venues\Command\DescribeVenuePhoto;
use Symfony\Component\Form\Util\StringUtil;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The photo manager's alt save (#25, D13, PF2), `{"loadedAt": "<token>", "alts": {"<locale>": "<text>"}}`,
 * validated with the validator rather than a form. Only enabled locales may appear, because the handler
 * refuses any other key with an `\InvalidArgumentException`. A value of the wrong JSON type becomes null
 * here and fails its rule, so a crafted body is a 422, never a 500. Alts are trimmed as a form field is
 * (`StringUtil::trim()`), so the length rule counts what is stored, also for a direct API call.
 */
final class PhotoAltData
{
    public const int ALT_MAX = 700;

    #[Assert\NotBlank(message: 'cms.venue.loaded_at.invalid')]
    #[Assert\Regex(pattern: '/\A\d{1,12}\z/', message: 'cms.venue.loaded_at.invalid', normalizer: 'mb_scrub')]
    public ?string $loadedAt = null;

    /** @var ?array<mixed> locale → alt text */
    #[Assert\NotNull(message: 'cms.photo.alts.invalid')]
    #[Assert\All([new Assert\Sequentially([
        new Assert\Type('string', message: 'cms.photo.alts.invalid'),
        new CleanText(),
        new Assert\Length(max: self::ALT_MAX, maxMessage: 'cms.venue.too_long'),
    ])])]
    public ?array $alts = null;

    /**
     * @param list<string> $locales the enabled locales
     */
    private function __construct(
        private readonly array $locales,
    ) {
    }

    /**
     * @param array<mixed> $payload the decoded JSON body
     * @param list<string> $locales the enabled locales
     */
    public static function fromPayload(array $payload, array $locales): self
    {
        $data = new self($locales);
        $data->loadedAt = \is_string($payload['loadedAt'] ?? null) ? $payload['loadedAt'] : null;
        $data->alts = \is_array($payload['alts'] ?? null) ? array_map(static fn (mixed $alt): mixed => \is_string($alt) ? StringUtil::trim($alt) : $alt, $payload['alts']) : null;

        return $data;
    }

    /**
     * Call only after the data validated.
     */
    public function toCommand(int $venueNumber, string $photoId): DescribeVenuePhoto
    {
        $alts = [];
        foreach ($this->alts ?? [] as $locale => $alt) {
            \assert(\is_string($alt));
            $alts[(string) $locale] = $alt;
        }

        return new DescribeVenuePhoto($venueNumber, $photoId, (string) $this->loadedAt, $alts);
    }

    #[Assert\Callback]
    public function validateLocales(ExecutionContextInterface $context): void
    {
        foreach (array_keys($this->alts ?? []) as $locale) {
            if (!\in_array($locale, $this->locales, true)) {
                // The path names the field, not the key: a crafted key is no text the message may echo.
                $context->buildViolation('cms.photo.alts.invalid')->atPath('alts')->addViolation();

                return;
            }
        }
    }
}
