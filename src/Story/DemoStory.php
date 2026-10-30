<?php

declare(strict_types=1);

namespace App\Story;

use App\Factory\RoomFactory;
use App\Factory\RoomTranslationFactory;
use App\Factory\VenueDocumentFactory;
use App\Factory\VenueDocumentTranslationFactory;
use App\Factory\VenueFactory;
use App\Factory\VenueRatingHighlightFactory;
use App\Factory\VenueReviewFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Town;
use App\Geo\Repository\TownRepository;
use App\Geo\ValueObject\Canton;
use App\Venues\Entity\TaxonomyTerm;
use App\Venues\Entity\Venue;
use App\Venues\Repository\TaxonomyTermRepository;
use App\Venues\Repository\VenueRepository;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\RatingHighlight;
use App\Venues\ValueObject\ReviewerRole;
use App\Venues\ValueObject\Seating;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\Tier;
use Faker\Generator;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Zenstruck\Foundry\Story;

use function Zenstruck\Foundry\faker;
use function Zenstruck\Foundry\Persistence\flush_after;

/**
 * 60 fictional demo venues (S8), loaded by `make seed` through AppStory.
 *
 * - Every canton (venue i is in canton i % 26, in its 1st–3rd most populous town) and every
 *   tier (3/6/9/12/30, spread over the cantons).
 * - 1–5 rooms, 0–3 reviews, 0–2 documents, terms of every group (one main building and
 *   one main setting each, "Hochzeit" always), about one in three without a rating.
 * - Deterministic: faker is seeded and the distribution is index-based.
 * - Idempotent: it does nothing when venues exist, because `make seed` appends.
 * - Needs the towns of app:geo:import-towns (make seed runs it first). No photos (#5).
 *
 * Names come from the word lists below and texts from faker; nothing is taken from
 * eventlokale.
 */
final class DemoStory extends Story
{
    public const VENUES = 60;

    /** Venue i is named PREFIXES[i % 12] + SUFFIXES[i % 15]: 60 distinct pairs (lcm 12, 15). */
    private const PREFIXES = [
        'Landgut', 'Seehaus', 'Gasthof', 'Schlossgut', 'Waldhaus', 'Villa',
        'Remise', 'Gutshof', 'Kulturscheune', 'Zunftsaal', 'Alpstube', 'Rebhaus',
    ];
    private const SUFFIXES = [
        'Erlenhof', 'Mittelwil', 'Sonnmatt', 'Lindenegg', 'Buchenrain', 'Ahornbühl', 'Talwiese',
        'Seeblick', 'Felsenau', 'Eichholz', 'Tannenberg', 'Wiesental', 'Rebhalde', 'Mühlerain', 'Kirschgarten',
    ];

    /** 3 + 6 + 9 + 12 + 30 = 60. */
    private const TIER_COUNTS = ['beliebte_location' => 3, 'empfehlung' => 6, 'top_premium' => 9, 'premium' => 12, 'basic' => 30];

    /** Venue i gets TIERS[(i * 13) % 60]; 13 is coprime to 60, so the tiers spread over the cantons. */
    private const TIER_STRIDE = 13;

    private const CAPACITIES = [40, 60, 80, 100, 120, 150, 180, 200, 250, 300, 400, 500, 600, 800, 1000, 1200];
    private const ROOM_NAMES = ['Saal', 'Foyer', 'Gewölbekeller', 'Salon', 'Wintergarten'];
    private const DOCUMENT_TITLES = ['Preisliste', 'Bankettmappe'];

    public function __construct(
        private readonly VenueRepository $venues,
        private readonly TaxonomyTermRepository $terms,
        private readonly TownRepository $towns,
    ) {
    }

    public function build(): void
    {
        if ($this->venues->count([]) > 0) {
            return;
        }
        if (0 === $this->towns->count([])) {
            throw new \LogicException('DemoStory needs towns: run "bin/console app:geo:import-towns" first (make seed does).');
        }

        $faker = faker();
        $faker->seed(20261030);
        $slugger = new AsciiSlugger('de');
        $terms = [];
        foreach (TermGroup::cases() as $group) {
            $terms[$group->value] = $this->terms->byGroup($group);
        }
        $tiers = [];
        foreach (self::TIER_COUNTS as $tier => $count) {
            array_push($tiers, ...array_fill(0, $count, Tier::from($tier)));
        }

        for ($i = 0; $i < self::VENUES; ++$i) {
            $canton = Canton::cases()[$i % 26];
            $towns = $this->towns->findBy(['canton' => $canton->value], ['population' => 'DESC', 'name' => 'ASC'], 3);
            $town = $towns[intdiv($i, 26)] ?? $towns[0] ?? throw new \LogicException(\sprintf('No town in canton %s.', $canton->value));
            $name = self::PREFIXES[$i % 12].' '.self::SUFFIXES[$i % 15];
            $tier = $tiers[($i * self::TIER_STRIDE) % self::VENUES];

            flush_after(fn () => $this->createVenue($faker, $i, $name, $slugger->slug($name)->lower()->toString(), $tier, $town, $terms));
        }
    }

    /**
     * @param array<string, list<TaxonomyTerm>> $terms
     */
    private function createVenue(Generator $faker, int $i, string $name, string $slug, Tier $tier, Town $town, array $terms): void
    {
        $capacityMax = (int) $faker->randomElement(self::CAPACITIES);
        $factory = VenueFactory::new()->withFaq();
        if (0 !== $i % 4) {
            $factory = $factory->withContact();
        }
        if (0 !== $i % 3) {
            $factory = $factory->withDistances();
        }
        // About one in three is unrated; venue 4 is rated below the first label (7.7).
        $rated = 2 !== $i % 3;
        if ($rated) {
            $factory = $factory->rated(4 === $i ? '7.4' : \sprintf('%.1f', $faker->randomFloat(1, 7.0, 9.6)), $faker->numberBetween(1, 400));
        }

        $venue = $factory->create([
            'name' => $name,
            'tier' => $tier,
            'capacityMax' => $capacityMax,
            'capacityMin' => $faker->boolean(35) ? null : $faker->numberBetween(10, intdiv($capacityMax, 2)),
            'town' => $town,
            'street' => $faker->streetAddress(),
        ]);

        VenueTranslationFactory::createOne([
            'venue' => $venue,
            'locale' => 'de',
            'slug' => $slug,
            'summary' => self::text($faker, 190),
            'description' => 0 === $i % 2 ? self::text($faker, 900) : null,
            'metaDescription' => self::text($faker, 150),
            'parkingText' => $faker->boolean() ? self::text($faker, 200) : null,
        ]);

        $this->createRooms($faker, $venue, $capacityMax);
        $this->assignTerms($faker, $venue, $terms);

        if ($rated) {
            foreach (array_values($faker->randomElements(RatingHighlight::cases(), $faker->numberBetween(0, 3))) as $index => $highlight) {
                VenueRatingHighlightFactory::createOne(['venue' => $venue, 'position' => $index + 1, 'code' => $highlight]);
            }
        }

        for ($review = $faker->numberBetween(0, 3); $review > 0; --$review) {
            VenueReviewFactory::createOne([
                'venue' => $venue,
                'locale' => 'de',
                'firstName' => $faker->firstName(),
                'role' => $faker->randomElement(ReviewerRole::cases()),
                'reviewedMonth' => \DateTimeImmutable::createFromMutable($faker->dateTimeBetween('-3 years', 'now')),
                'text' => self::text($faker, 300),
            ]);
        }

        for ($position = 1, $documents = $faker->numberBetween(0, 2); $position <= $documents; ++$position) {
            VenueDocumentTranslationFactory::createOne([
                'document' => VenueDocumentFactory::new([
                    'venue' => $venue,
                    'position' => $position,
                    'path' => \sprintf('demo/%s/%d.pdf', $slug, $position),
                    'sizeBytes' => $faker->numberBetween(40_000, 2_500_000),
                ]),
                'locale' => 'de',
                'title' => self::DOCUMENT_TITLES[$position - 1],
            ]);
        }
    }

    /**
     * 1–5 rooms; the first one holds the venue's full capacity. The room facts on the venue
     * are computed from them.
     */
    private function createRooms(Generator $faker, Venue $venue, int $capacityMax): void
    {
        $count = $faker->numberBetween(1, 5);
        $largest = 0;
        $tallest = 0.0;
        for ($position = 1; $position <= $count; ++$position) {
            $roomMax = 1 === $position ? $capacityMax : $faker->numberBetween(max(10, intdiv($capacityMax, 5)), $capacityMax);
            $factory = RoomFactory::new();
            foreach (Seating::cases() as $seating) {
                if ($faker->boolean(60)) {
                    $seatingMax = $faker->numberBetween(max(1, intdiv($roomMax, 3)), $roomMax);
                    $factory = $factory->withSeating($seating, $faker->boolean() ? null : $faker->numberBetween(1, $seatingMax), $seatingMax);
                }
            }

            $room = $factory->create([
                'venue' => $venue,
                'position' => $position,
                'capacityMin' => $faker->boolean() ? null : $faker->numberBetween(1, intdiv($roomMax, 2)),
                'capacityMax' => $roomMax,
                'areaM2' => $faker->numberBetween($roomMax, $roomMax * 2),
                'heightM' => \sprintf('%.2f', $faker->randomFloat(2, 2.4, 9.5)),
                'floorMaterial' => $faker->randomElement([...FloorMaterial::cases(), null]),
            ]);
            RoomTranslationFactory::createOne(['room' => $room, 'locale' => 'de', 'name' => self::ROOM_NAMES[$position - 1], 'description' => $faker->boolean() ? self::text($faker, 300) : null]);
            $largest = max($largest, (int) $room->areaM2());
            $tallest = max($tallest, (float) $room->heightM());
        }

        $venue->summariseRooms($count, $largest, \sprintf('%.2f', $tallest));
    }

    /**
     * 1–2 settings and 1–3 buildings (the first of each is the main one), "Hochzeit" plus
     * 0–4 other occasions, and 0–4 terms of every other group.
     *
     * @param array<string, list<TaxonomyTerm>> $terms
     */
    private function assignTerms(Generator $faker, Venue $venue, array $terms): void
    {
        $picks = [
            'setting' => $faker->randomElements($terms['setting'], $faker->numberBetween(1, 2)),
            'building' => $faker->randomElements($terms['building'], $faker->numberBetween(1, 3)),
            'suitable_for' => [
                $terms['suitable_for'][0],
                ...$faker->randomElements(\array_slice($terms['suitable_for'], 1), $faker->numberBetween(0, 4)),
            ],
        ];
        foreach (['infrastructure', 'furniture', 'technology', 'surroundings'] as $group) {
            $picks[$group] = $faker->randomElements($terms[$group], $faker->numberBetween(0, 4));
        }

        foreach ($picks as $group => $groupTerms) {
            foreach (array_values($groupTerms) as $index => $term) {
                VenueTermFactory::createOne([
                    'venue' => $venue,
                    'term' => $term,
                    'isMain' => 0 === $index && \in_array($group, ['setting', 'building'], true),
                ]);
            }
        }
    }

    /**
     * Faker's German text corpus is old High German; Swiss German writes "ss" for "ß".
     */
    private static function text(Generator $faker, int $maxChars): string
    {
        return str_replace('ß', 'ss', $faker->realText($maxChars));
    }
}
