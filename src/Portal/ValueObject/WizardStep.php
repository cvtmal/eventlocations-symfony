<?php

declare(strict_types=1);

namespace App\Portal\ValueObject;

/**
 * The pages of the location wizard (#45), in order, the case's value being the URL segment. There is no wizard table:
 * the step a manager lands on is derived from what the location lacks. `veranstaltung` is never derived, because no gap
 * belongs to it, and it shares its progress position with `merkmale`.
 */
enum WizardStep: string
{
    case Texts = 'texte';
    case Terms = 'merkmale';
    case Event = 'veranstaltung';
    case Photos = 'fotos';
    case Contact = 'kontakt';
    case Check = 'pruefen';

    public const string REQUIREMENT = 'texte|merkmale|veranstaltung|fotos|kontakt|pruefen';
    public const int LAST_POSITION = 6;

    /** The gap codes in the wizard's order: the first one missing decides. */
    private const array GAPS = [
        'summary' => self::Texts,
        'main_building' => self::Terms,
        'photo' => self::Photos,
        'inquiry_email' => self::Contact,
    ];

    /**
     * @param list<string> $missing gap codes
     */
    public static function derive(array $missing): self
    {
        foreach (self::GAPS as $code => $step) {
            if (\in_array($code, $missing, true)) {
                return $step;
            }
        }

        return self::Check;
    }

    /**
     * The draft rule's gaps (F14, D54) concern only entries nobody manages, so a manager's wizard never meets them and
     * `derive()` ignores them; they still have a page here so the match stays total: the description is a text, the
     * facts check is staff's, after the check page.
     */
    public static function forGap(string $code): self
    {
        return self::GAPS[$code] ?? match ($code) {
            'description' => self::Texts,
            'facts' => self::Check,
            default => throw new \LogicException(\sprintf('Unknown gap "%s".', $code)),
        };
    }

    public function next(): self
    {
        return match ($this) {
            self::Texts => self::Terms,
            self::Terms => self::Event,
            self::Event => self::Photos,
            self::Photos => self::Contact,
            self::Contact, self::Check => self::Check,
        };
    }

    public function position(): int
    {
        return match ($this) {
            self::Texts => 2,
            self::Terms, self::Event => 3,
            self::Photos => 4,
            self::Contact => 5,
            self::Check => 6,
        };
    }

    public function card(): ?LocationCard
    {
        return match ($this) {
            self::Texts => LocationCard::Texts,
            self::Terms => LocationCard::Terms,
            self::Event => LocationCard::Event,
            self::Contact => LocationCard::Contact,
            self::Photos, self::Check => null,
        };
    }
}
