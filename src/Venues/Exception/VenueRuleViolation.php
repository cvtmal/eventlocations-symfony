<?php

declare(strict_types=1);

namespace App\Venues\Exception;

use App\Shared\Exception\DomainException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * A venue rule a CMS save broke (#24, #25). The message is a key of the `cms` catalogue, with
 * `parameters` for it. `field` names the form field the error belongs to (`slug`, `town`,
 * `lat`); null means the whole form.
 */
final class VenueRuleViolation extends DomainException
{
    /**
     * @param array<string, int|string> $parameters
     * @param list<string>              $missing    what a publish lacks: `summary`, `main_building`, `photo`
     */
    private function __construct(
        string $translationKey,
        public readonly ?string $field = null,
        public readonly array $parameters = [],
        public readonly array $missing = [],
    ) {
        parent::__construct($translationKey);
    }

    public static function slugTaken(): self
    {
        return new self('venue.error.slug_taken', 'slug');
    }

    public static function slugWithoutLetters(): self
    {
        return new self('venue.error.slug_without_letters', 'slug');
    }

    public static function unknownTown(): self
    {
        return new self('venue.error.unknown_town', 'town');
    }

    public static function ambiguousTown(): self
    {
        return new self('venue.error.ambiguous_town', 'town');
    }

    public static function townCentreNeedsTown(): self
    {
        return new self('venue.error.town_centre_needs_town', 'town');
    }

    public static function coordinatesRequired(): self
    {
        return new self('venue.error.coordinates_required', 'lat');
    }

    /**
     * @param list<string> $missing `summary`, `main_building`, `photo`
     */
    public static function notPublishable(array $missing): self
    {
        return new self('venue.error.not_publishable', null, ['count' => \count($missing)], $missing);
    }

    public static function stillPublished(): self
    {
        return new self('venue.error.still_published');
    }

    public static function tooManyPhotos(): self
    {
        return new self('venue.error.too_many_photos', 'file');
    }

    /**
     * The PDF is already one of the venue's documents (#25: same bytes, same path).
     */
    public static function documentAlreadyUploaded(): self
    {
        return new self('documents.error.already_uploaded', 'file');
    }

    /**
     * A document needs a title in the default locale (#25).
     */
    public static function documentTitleRequired(string $defaultLocale): self
    {
        return new self('documents.error.title_required', 'titles.'.$defaultLocale);
    }

    /**
     * A room needs a name in the default locale (#25).
     */
    public static function roomNameRequired(string $defaultLocale): self
    {
        return new self('rooms.error.name_required', 'names.'.$defaultLocale);
    }

    /**
     * The bus wraps what a handler throws. Returns the rule violation inside, or rethrows the original.
     */
    public static function unwrap(HandlerFailedException $failure): self
    {
        foreach ($failure->getWrappedExceptions(self::class) as $violation) {
            return $violation;
        }

        throw $failure;
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            self::slugTaken(),
            self::slugWithoutLetters(),
            self::unknownTown(),
            self::ambiguousTown(),
            self::townCentreNeedsTown(),
            self::coordinatesRequired(),
            self::notPublishable(['summary']),
            self::stillPublished(),
            self::tooManyPhotos(),
            self::documentAlreadyUploaded(),
            self::documentTitleRequired('de'),
            self::roomNameRequired('de'),
        ];
    }

    public function translationKey(): string
    {
        return $this->getMessage();
    }
}
