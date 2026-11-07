<?php

declare(strict_types=1);

namespace App\Story;

use App\Factory\PhotoCandidateFactory;
use App\Factory\RoomFactory;
use App\Factory\RoomTranslationFactory;
use App\Factory\SourceRecordFactory;
use App\Factory\VenueDocumentFactory;
use App\Factory\VenueDocumentTranslationFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenuePhotoTranslationFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Town;
use App\Geo\Repository\TownRepository;
use App\Geo\ValueObject\Canton;
use App\Venues\Entity\Room;
use App\Venues\Entity\TaxonomyTerm;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueDocument;
use App\Venues\Entity\VenueTranslation;
use App\Venues\Repository\TaxonomyTermRepository;
use App\Venues\Repository\VenueRepository;
use App\Venues\ValueObject\CandidateOrigin;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\ImageCandidate;
use App\Venues\ValueObject\PartyAllowed;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\Seating;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\Tier;
use Faker\Generator;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Zenstruck\Foundry\Story;

use function Zenstruck\Foundry\faker;
use function Zenstruck\Foundry\Persistence\flush_after;

/**
 * 60 fictional demo venues (S8), loaded by `make seed` through AppStory.
 *
 * - Every canton (venue i is in canton i % 26, in its 1st–3rd most populous town), plus one
 *   Liechtenstein venue (#46), and every tier (3/6/9/12/30, spread over the cantons).
 * - 1–5 rooms, 0–2 documents, terms of every group (one main building and one main setting
 *   each, "Hochzeit" always). No own rating or reviews: #53 retired them.
 * - Deterministic: faker is seeded and the distribution is index-based.
 * - Idempotent: it does nothing when venues exist, because `make seed` appends.
 * - Needs the towns of app:geo:import-towns (make seed runs it first).
 * - Photos (#5): 3–8 gallery photos per venue (8 for every tenth), 1–2 in the first room of
 *   every third venue, from DemoMedia's 30 drawn originals; odd positions get a German alt
 *   row, even ones exercise the fallback alt. Every document gets a real one-page PDF, so
 *   size_bytes is its size. Media is created after all venues and uses its own seeded RNG,
 *   so the venue data stays exactly #4's (factory defaults draw from the shared faker).
 * - Variants are cached in var/demo-media/ per original: the first, cold `make seed` renders
 *   them (10–15 s once), later loads only copy files into public/media/<number>/. Before
 *   publishing, a venue's public/media/<number>/ is emptied (numbers restart after db-fresh).
 * - Wedding facts (#31): every second venue (even index) has the owner facts of S14, the
 *   others none, so both states of the detail page stay visible. Their share of each fact is
 *   index-based, all four curfew states occur, and the terms come from a seeded RNG of their
 *   own, after the media step, so nothing before it shifts.
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
    private const ALT_PHRASES = [
        'Aussenansicht', 'Festsaal mit gedeckten Tischen', 'Garten im Sommer', 'Apéro auf der Terrasse',
        'Eingangsbereich', 'Blick in den Saal', 'Bankett bei Kerzenlicht', 'Lounge am Abend',
    ];
    private const AMBIENCES = [
        'Romantisch mit Seesicht', 'Rustikal im Grünen', 'Elegant und hell', 'Historisch mit Charme',
        'Ländlich mit Weitblick', 'Alpin und gemütlich', 'Urban und unkompliziert',
    ];

    /**
     * Venues in the owner's shape (#21): no address, a canton, a region text, or nothing. Each
     * is the third of its canton, so every canton keeps its two venues with a town. 52 is
     * Zürich's third; 53 is the Liechtenstein venue (#46), so Bern keeps its two venues with a
     * town.
     */
    private const CANTON_ONLY = [52, self::LIECHTENSTEIN];
    /** Canton only, no town, like the Liechtenstein venues of the owner's export (#46). */
    private const LIECHTENSTEIN = 53;
    /** Its phone, so the reveal shows the `+423` format in dev. */
    private const LIECHTENSTEIN_PHONE = '+4232345678';
    private const REGION_ONLY = 54;
    private const NO_PLACE = 55;
    private const REGION_TEXT = 'Schweizweit';

    public function __construct(
        private readonly VenueRepository $venues,
        private readonly TaxonomyTermRepository $terms,
        private readonly TownRepository $towns,
        private readonly DemoMedia $media,
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
        $created = [];
        $tiers = [];
        foreach (self::TIER_COUNTS as $tier => $count) {
            array_push($tiers, ...array_fill(0, $count, Tier::from($tier)));
        }

        for ($i = 0; $i < self::VENUES; ++$i) {
            // The rotation covers the 26 cantons only; Liechtenstein gets venue 53 alone.
            $canton = self::LIECHTENSTEIN === $i ? Canton::LI : Canton::cases()[$i % 26];
            $towns = $this->towns->findBy(['canton' => $canton->value], ['population' => 'DESC', 'name' => 'ASC'], 3);
            $town = $towns[intdiv($i, 26)] ?? $towns[0] ?? throw new \LogicException(\sprintf('No town in canton %s.', $canton->value));
            $name = self::PREFIXES[$i % 12].' '.self::SUFFIXES[$i % 15];
            $tier = $tiers[($i * self::TIER_STRIDE) % self::VENUES];

            $created[$i] = [$name, ...flush_after(fn (): array => $this->createVenue($faker, $i, $name, $slugger->slug($name)->lower()->toString(), $tier, $town, $terms))];
        }

        // After the loop: the photo factories' defaults draw from the shared faker, which must
        // not shift the venue data. The venue numbers exist now (identity, set on flush).
        foreach ($created as $i => [$name, $venue, $rooms, $documents]) {
            flush_after(fn () => $this->createMedia($i, $name, $venue, $rooms, $documents));
        }

        flush_after(fn () => $this->createWeddingFacts($created, $terms));
        flush_after(fn () => $this->createSiteImport($created[0][1]));
    }

    /**
     * One private website import (G3) with invented facts, so `make seed` has a source record and photo candidates to
     * look at in the database: no page reads them.
     */
    private function createSiteImport(Venue $venue): void
    {
        $url = 'https://www.beispiel-venue.example/';
        $fact = static fn (string|int|array $value, float $confidence, string $via, string $page = ''): array => ['value' => $value, 'confidence' => $confidence, 'source_url' => $url.$page, 'via' => $via];
        $record = SourceRecordFactory::createOne([
            'venue' => $venue,
            'url' => $url,
            'facts' => [
                'name' => [$fact('Beispiel Venue', 0.9, 'json_ld'), $fact('Beispiel Venue - Events und Seminare', 0.4, 'title')],
                'address' => [$fact(['street' => 'Musterweg 12', 'postal_code' => '8000', 'locality' => 'Zürich', 'country' => 'CH'], 0.9, 'json_ld')],
                'phone' => [$fact('+41 44 555 01 23', 0.8, 'link', 'kontakt')],
                'snippet' => [$fact('Der Saal bietet Platz für bis zu 120 Personen.', 0.4, 'text', 'raeume')],
            ],
            'report' => [
                'final_url' => $url,
                'redirects' => [],
                'pages' => [['url' => $url, 'status' => 200, 'bytes' => 18200], ['url' => $url.'raeume', 'status' => 200, 'bytes' => 9400]],
                'refused' => [['url' => $url.'intern', 'reason' => 'robots', 'detail' => '/intern is disallowed by robots.txt']],
                'skipped_images' => 3,
                'bytes' => 27600,
                'seconds' => 3.4,
            ],
        ]);
        foreach ([
            new ImageCandidate($url.'media/saal.jpg', $url.'raeume', CandidateOrigin::OgImage, 'Der Saal', 'Unsere Räume', 1600, 900),
            new ImageCandidate($url.'media/terrasse.jpg', $url.'raeume', CandidateOrigin::Srcset, 'Die Terrasse', 'Unsere Räume', 1200, null),
            new ImageCandidate($url.'media/eingang.jpg', $url, CandidateOrigin::Img),
        ] as $position => $image) {
            PhotoCandidateFactory::createOne(['sourceRecord' => $record, 'image' => $image, 'position' => $position + 1]);
        }
    }

    /**
     * @param array<string, list<TaxonomyTerm>> $terms
     *
     * @return array{Venue, list<Room>, list<VenueDocument>, VenueTranslation}
     */
    private function createVenue(Generator $faker, int $i, string $name, string $slug, Tier $tier, Town $town, array $terms): array
    {
        $capacityMax = (int) $faker->randomElement(self::CAPACITIES);
        $factory = VenueFactory::new()->withFaq();
        if (0 !== $i % 4) {
            $factory = $factory->withContact();
        }
        if (self::LIECHTENSTEIN === $i) {
            // After withContact(), whose faker draws stay, so every other venue keeps its data.
            $factory = $factory->afterInstantiate(static fn (Venue $venue) => $venue->changeContact(
                self::LIECHTENSTEIN_PHONE,
                $venue->inquiryEmail(),
                null === $venue->website() ? null : str_replace('.example.ch', '.example.li', $venue->website()),
                $venue->contactPerson(),
            ));
        }
        if (0 !== $i % 3) {
            $factory = $factory->withDistances();
        }
        $hasAddress = !\in_array($i, [...self::CANTON_ONLY, self::REGION_ONLY, self::NO_PLACE], true);
        if (!$hasAddress) {
            $factory = $factory->withoutLocation(\in_array($i, self::CANTON_ONLY, true) ? $town->canton() : null);
        }
        $attributes = [
            'name' => $name,
            'tier' => $tier,
            'capacityMax' => $capacityMax,
            'capacityMin' => $faker->boolean(35) ? null : $faker->numberBetween(10, intdiv($capacityMax, 2)),
            'town' => $town,
            // Drawn for every venue, so faker's sequence and the other venues stay as they were.
            'street' => $faker->streetAddress(),
        ];
        $venue = $factory->create($hasAddress ? $attributes : array_diff_key($attributes, ['town' => true, 'street' => true]));

        $translation = VenueTranslationFactory::createOne([
            'venue' => $venue,
            'locale' => 'de',
            'slug' => $slug,
            'summary' => self::text($faker, 190),
            'description' => 0 === $i % 2 ? self::text($faker, 900) : null,
            'metaDescription' => self::text($faker, 150),
            'parkingText' => $faker->boolean() ? self::text($faker, 200) : null,
        ]);
        if (self::REGION_ONLY === $i) {
            $translation->changeRegion(self::REGION_TEXT);
        }

        $rooms = $this->createRooms($faker, $venue, $capacityMax);
        $this->assignTerms($faker, $venue, $terms);

        $documents = [];
        for ($position = 1, $count = $faker->numberBetween(0, 2); $position <= $count; ++$position) {
            // The fictional size of #4 is still drawn (and discarded), so faker's sequence stays #4's.
            $faker->numberBetween(40_000, 2_500_000);
            $path = \sprintf('demo/%s/%d.pdf', $slug, $position);
            $documents[] = VenueDocumentTranslationFactory::createOne([
                'document' => VenueDocumentFactory::new([
                    'venue' => $venue,
                    'position' => $position,
                    'path' => $path,
                    'sizeBytes' => $this->media->writePdf($path, self::DOCUMENT_TITLES[$position - 1].' '.$name),
                ]),
                'locale' => 'de',
                'title' => self::DOCUMENT_TITLES[$position - 1],
            ])->document();
        }

        return [$venue, $rooms, $documents, $translation];
    }

    /**
     * The owner facts of the even-index venues (see the class docblock). All fictional.
     *
     * @param array<int, array{string, Venue, list<Room>, list<VenueDocument>, VenueTranslation}> $created by index
     * @param array<string, list<TaxonomyTerm>>                                                   $terms
     */
    private function createWeddingFacts(array $created, array $terms): void
    {
        foreach ($created as $i => [, $venue, , , $translation]) {
            if (0 !== $i % 2) {
                continue;
            }
            $random = new Randomizer(new Mt19937(20261030 + $i));
            $step = intdiv($i, 2);

            $venue->changePriceLevel(PriceLevel::cases()[$step % 3]);
            if (0 !== $step % 5) {
                $venue->changeSeatingCapacity(max(1, intdiv($venue->capacityMax() * 2, 3)), $venue->capacityMax());
            }
            match ($step % 4) {
                1 => $venue->describePartyRules(PartyAllowed::Yes, false, null),
                2 => $venue->describePartyRules(PartyAllowed::Limited, true, new \DateTimeImmutable('1970-01-01 02:00:00')),
                3 => $venue->describePartyRules(PartyAllowed::No, true, null),
                default => null,
            };
            if (0 === $step % 3) {
                $venue->changeInstagram('https://www.instagram.com/demo_location_'.$i);
            }
            $translation->describeWedding(
                self::AMBIENCES[$step % \count(self::AMBIENCES)],
                0 === $step % 4 ? 'Mit Tanzfläche sind es etwas weniger Sitzplätze.' : null,
                2 === $step % 4 ? 'Ab Mitternacht nur noch Musik in Zimmerlautstärke.' : null,
                0 === $step % 3 ? 'Mai bis Oktober, täglich ab 10 Uhr' : null,
            );

            foreach ([TermGroup::Ceremony, TermGroup::Amenity, TermGroup::Dining, TermGroup::Vendor, TermGroup::Usage, TermGroup::Language] as $group) {
                $count = TermGroup::Usage === $group ? 1 : $random->getInt(0, 3);
                if (0 === $count) {
                    continue;
                }
                $groupTerms = $terms[$group->value];
                \assert([] !== $groupTerms);
                foreach ($random->pickArrayKeys($groupTerms, $count) as $key) {
                    VenueTermFactory::createOne(['venue' => $venue, 'term' => $groupTerms[$key], 'isMain' => false]);
                }
            }
        }
    }

    /**
     * Publishes the venue's PDFs and gives it its photos (see the class docblock).
     *
     * @param list<Room>          $rooms
     * @param list<VenueDocument> $documents
     */
    private function createMedia(int $i, string $name, Venue $venue, array $rooms, array $documents): void
    {
        $number = $venue->number();
        $this->media->resetVenue($number);
        foreach ($documents as $document) {
            $this->media->publishDocument($document->path(), $number, $document->id());
        }

        $random = new Randomizer(new Mt19937(20261030 + $i));
        $originals = array_fill(1, DemoMedia::ORIGINALS, true);
        $gallery = $random->shuffleArray($random->pickArrayKeys($originals, 0 === $i % 10 ? 8 : $random->getInt(3, 8)));
        foreach ($gallery as $index => $original) {
            $this->createPhoto($random, $name, $venue, null, $index + 1, (int) $original, $number);
        }
        if (0 === $i % 3) {
            foreach ($random->pickArrayKeys($originals, $random->getInt(1, 2)) as $index => $original) {
                $this->createPhoto($random, $name, $venue, $rooms[0], $index + 1, (int) $original, $number);
            }
        }
    }

    private function createPhoto(Randomizer $random, string $name, Venue $venue, ?Room $room, int $position, int $original, int $number): void
    {
        \assert($original >= 1 && $original <= DemoMedia::ORIGINALS);
        [$image, $directory] = $this->media->variants($original);
        $photo = VenuePhotoFactory::createOne([
            'venue' => $venue,
            'room' => $room,
            'position' => $position,
            'originalPath' => DemoMedia::originalPath($original),
            'image' => $image,
        ]);
        $this->media->publishPhoto($image, $directory, $number, $photo->id());

        if (1 === $position % 2) {
            VenuePhotoTranslationFactory::createOne([
                'photo' => $photo,
                'locale' => 'de',
                'alt' => self::ALT_PHRASES[$random->getInt(0, \count(self::ALT_PHRASES) - 1)].', '.$name,
            ]);
        }
    }

    /**
     * 1–5 rooms; the first one holds the venue's full capacity. The room facts on the venue
     * are computed from them.
     *
     * @return list<Room>
     */
    private function createRooms(Generator $faker, Venue $venue, int $capacityMax): array
    {
        $rooms = [];
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
            $rooms[] = $room;
            RoomTranslationFactory::createOne(['room' => $room, 'locale' => 'de', 'name' => self::ROOM_NAMES[$position - 1], 'description' => $faker->boolean() ? self::text($faker, 300) : null]);
            $largest = max($largest, (int) $room->areaM2());
            $tallest = max($tallest, (float) $room->heightM());
        }

        $venue->summariseRooms($count, $largest, \sprintf('%.2f', $tallest));

        return $rooms;
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
