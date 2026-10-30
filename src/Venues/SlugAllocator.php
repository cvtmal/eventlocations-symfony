<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\Repository\VenueRepository;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Chooses a venue's URL slug from its name (#9, D7): the name's slug, else
 * `<slug>-<town-slug>`, else `<slug>-<town-slug>-2`, `-3`, …, each at most 160 characters.
 * A venue without a town goes from the name's slug straight to `<slug>-2`, `-3`, … A slug is
 * taken when another venue has it in the locale, as current or history slug.
 */
final class SlugAllocator
{
    public const int MAX_LENGTH = 160;

    public function __construct(
        private readonly VenueRepository $venues,
    ) {
    }

    /**
     * The slug of the name alone, cut at a hyphen to at most MAX_LENGTH; '' when the name has
     * no letters or digits.
     */
    public static function base(string $name): string
    {
        return self::cut((new AsciiSlugger('de'))->slug($name)->lower()->toString(), self::MAX_LENGTH);
    }

    /**
     * @return \Generator<int, string>
     */
    public static function candidates(string $name, ?string $townSlug): \Generator
    {
        $base = self::base($name);
        if ('' === $base) {
            return;
        }
        yield $base;

        $tail = null === $townSlug ? '' : '-'.$townSlug;
        $withTail = static fn (string $suffix): string => self::cut($base, self::MAX_LENGTH - \strlen($tail.$suffix)).$tail.$suffix;
        if (null !== $townSlug) {
            yield $withTail('');
        }
        for ($n = 2;; ++$n) {
            yield $withTail('-'.$n);
        }
    }

    public function allocate(string $locale, string $name, ?string $townSlug, ?string $venueId): string
    {
        foreach (self::candidates($name, $townSlug) as $candidate) {
            if (!$this->venues->isSlugTaken($locale, $candidate, $venueId)) {
                return $candidate;
            }
        }

        throw new \LogicException(\sprintf('The name "%s" gives no slug.', $name));
    }

    /**
     * Whether another venue than $venueId holds the slug in this locale, now or in its
     * history. The venue's own history slugs are free for it to take back.
     */
    public function isTaken(string $locale, string $slug, ?string $venueId): bool
    {
        return $this->venues->isSlugTaken($locale, $slug, $venueId);
    }

    /**
     * Cuts a slug to at most $length characters, at a hyphen when it has to cut.
     */
    private static function cut(string $slug, int $length): string
    {
        if (\strlen($slug) <= $length) {
            return $slug;
        }
        $cut = substr($slug, 0, $length + 1);
        $hyphen = strrpos($cut, '-');

        return rtrim(false === $hyphen || 0 === $hyphen ? substr($slug, 0, $length) : substr($cut, 0, $hyphen), '-');
    }
}
