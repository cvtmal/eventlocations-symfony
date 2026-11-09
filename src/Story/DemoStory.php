<?php

declare(strict_types=1);

namespace App\Story;

use App\Factory\LastMinuteOfferFactory;
use App\Factory\ModerationCaseFactory;
use App\Factory\ModerationCasePhotoFactory;
use App\Factory\PhotoCandidateFactory;
use App\Factory\RoomFactory;
use App\Factory\RoomTranslationFactory;
use App\Factory\SourceRecordFactory;
use App\Factory\SubscriptionFactory;
use App\Factory\VenueDocumentFactory;
use App\Factory\VenueDocumentTranslationFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenuePhotoTranslationFactory;
use App\Factory\VenuePreviewFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Factory\VenueVideoFactory;
use App\Geo\Entity\Town;
use App\Geo\Repository\TownRepository;
use App\Geo\ValueObject\Canton;
use App\Venues\Entity\Room;
use App\Venues\Entity\Subscription;
use App\Venues\Entity\TaxonomyTerm;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueDocument;
use App\Venues\Entity\VenuePhoto;
use App\Venues\Entity\VenueTerm;
use App\Venues\Entity\VenueTranslation;
use App\Venues\Repository\TaxonomyTermRepository;
use App\Venues\Repository\VenueRepository;
use App\Venues\ValueObject\CandidateOrigin;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\ImageCandidate;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\ModerationDecision;
use App\Venues\ValueObject\ModerationKind;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\Seating;
use App\Venues\ValueObject\TermGroup;
use Faker\Generator;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Zenstruck\Foundry\Story;

use function Zenstruck\Foundry\faker;
use function Zenstruck\Foundry\Persistence\delete;
use function Zenstruck\Foundry\Persistence\flush_after;
use function Zenstruck\Foundry\Persistence\repository;

/**
 * 60 fictional demo venues (S8), loaded by `make seed` through AppStory.
 *
 * - Every canton (venue i is in canton i % 26, in its 1st–3rd most populous town; 56-59 in the launch regions
 *   BE, ZG, AG, ZH, G12), plus one Liechtenstein venue (#46), and both tiers (15 Pro, 45 Free, spread over the cantons).
 *   Every Pro venue has a paid term (G9); three end within 60 days (one cancelled), see createTerms().
 * - Index gate (G12): every fifth venue has unchecked facts and every odd one no description, so about two in five
 *   entries are indexed and the launch regions show indexable and noindex landing pages in dev.
 * - 1–5 rooms, 0–2 documents, terms of the seven filter groups (one main building and one main
 *   setting each, 1–5 event types). No own rating or reviews: #53 retired them.
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
 * - Rights (G1): every photo gets a live grant from the factory (system, `staff_attestation`), so the demo site is
 *   public; the last gallery photo of one venue (UNGRANTED_VENUE) has none, so the "Bildrechte" card of the back
 *   office shows both states. It is stored and has variants, but no page shows it until it is granted.
 * - Event facts (#31, F3): every second venue (even index) has the event facts of S14, the
 *   others none, so both states of the detail page stay visible. Their share of each fact is
 *   index-based, and the terms (amenity, catering, language) come from a seeded RNG of their
 *   own, after the media step, so nothing before it shifts. Every fifth of them from the
 *   third on (index 4, 14, ..., 54) states a stale capacity, a third of its first room's maximum
 *   and no minimum (F5b), so the guest filter and the card capacity follow the room.
 *
 * - «Kurzfristig frei» (G14): offers on four Pro venues (a period in a named room with event types, a day next week, a
 *   day whose last day is today, a period with terms) and one on a Free venue that no page shows; see
 *   createLastMinuteOffers(). PortalStory gives the dev manager's Pro location one more.
 *
 * - Complaints (G2): an open rights complaint about venue 3 (index 2) hides its first gallery photo, a decided
 *   `keep` complaint about venue 4 names its first gallery photo (shown again), and an open correction names a
 *   location the site does not know, so the "Meldungen" queue of the back office shows each state.
 *
 * Names come from the word lists below and texts from faker; nothing is taken from
 * eventlokale.
 */
final class DemoStory extends Story
{
    public const VENUES = 60;

    /** F13: the demo venue (index) that opted out of briefs; it has an inquiry address (`0 !== $i % 4`). */
    private const int NO_BRIEFS = 2;

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
    private const TIER_COUNTS = [KnownTiers::PRO => 15, KnownTiers::FREE => 45];

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
        'Hell und modern', 'Rustikal im Grünen', 'Elegant und repräsentativ', 'Historisch mit Charme',
        'Industrial mit viel Platz', 'Alpin und gemütlich', 'Urban und unkompliziert',
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
    /** The venue whose last gallery photo stays ungranted (G1), venue 2 of the list. */
    private const UNGRANTED_VENUE = 1;
    /** The venue whose first gallery photo an open rights complaint hides (G2), and the one of a decided `keep`. */
    private const HELD_VENUE = 2;
    private const KEPT_VENUE = 3;
    /**
     * G12 seed focus (D21): the third venues 56-59 (else of SZ, OW, NW, GL, which keep two) go to the launch regions,
     * so ZH has four venues and BE, ZG and AG three each.
     */
    private const LAUNCH_FOCUS = [56 => Canton::BE, 57 => Canton::ZG, 58 => Canton::AG, 59 => Canton::ZH];
    /** G12: venue i has unchecked facts when i % 5 is this (not indexed; odd venues also lack a description). */
    private const UNCHECKED = 4;
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
            array_push($tiers, ...array_fill(0, $count, $tier));
        }

        for ($i = 0; $i < self::VENUES; ++$i) {
            // The rotation covers the 26 cantons only; Liechtenstein gets venue 53 alone; 56-59 go to the launch regions (G12).
            $canton = self::LIECHTENSTEIN === $i ? Canton::LI : (self::LAUNCH_FOCUS[$i] ?? Canton::cases()[$i % 26]);
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

        flush_after(fn () => $this->createEventFacts($created, $terms));
        flush_after(fn () => $this->createSiteImport($created[0][1]));
        flush_after(fn () => $this->createPreviewLinks($created[1][1]));
        flush_after(fn () => $this->createModerationCases($created[self::HELD_VENUE][1], $created[self::KEPT_VENUE][1]));
        flush_after(fn () => $this->createTerms(array_column($created, 1)));
        flush_after(fn () => $this->createVideos($created[0][1], $created[2][1]));
        flush_after(fn () => $this->createLastMinuteOffers($created));
    }

    /**
     * G14: offers relative to today, on the second to fifth Pro venue by index (the first is the dev manager's, see
     * PortalStory), and one on the first Free venue, hidden by its plan.
     *
     * @param array<int, array{string, Venue, list<Room>, list<VenueDocument>}> $created
     */
    private function createLastMinuteOffers(array $created): void
    {
        $today = new \DateTimeImmutable('today');
        $pro = array_values(array_filter($created, static fn (array $row): bool => KnownTiers::PRO === $row[1]->tier()->code()));
        $free = array_values(array_filter($created, static fn (array $row): bool => KnownTiers::FREE === $row[1]->tier()->code()));
        $suitableFor = static fn (Venue $venue): array => array_map(
            static fn (VenueTerm $term): string => $term->termCode(),
            repository(VenueTerm::class)->findBy(['venue' => $venue, 'termGroup' => TermGroup::SuitableFor]),
        );

        if (isset($pro[1])) {
            [, $venue, $rooms] = $pro[1];
            LastMinuteOfferFactory::createOne([
                'venue' => $venue,
                'room' => $rooms[0] ?? null,
                'startsOn' => $today->modify('+3 days'),
                'endsOn' => $today->modify('+5 days'),
                'eventTypes' => \array_slice($suitableFor($venue), 0, 2),
                'priceText' => 'Seminarpauschale ab CHF 89 pro Person',
                'minGuests' => 20,
            ]);
        }
        if (isset($pro[2])) {
            LastMinuteOfferFactory::createOne(['venue' => $pro[2][1], 'startsOn' => $today->modify('+7 days'), 'endsOn' => $today->modify('+7 days'), 'priceText' => 'Apéro-Paket für CHF 1500']);
        }
        if (isset($pro[3])) {
            LastMinuteOfferFactory::createOne(['venue' => $pro[3][1], 'startsOn' => $today, 'endsOn' => $today, 'priceText' => 'Saalmiete CHF 800 statt CHF 1200', 'terms' => 'Nur heute, ab 18 Uhr.']);
        }
        if (isset($pro[4])) {
            LastMinuteOfferFactory::createOne(['venue' => $pro[4][1], 'startsOn' => $today->modify('+10 days'), 'endsOn' => $today->modify('+16 days'), 'priceText' => 'Ganze Location ab CHF 2500 pro Tag', 'minGuests' => 40, 'terms' => 'Mindestens zwei Tage.']);
        }
        if (isset($free[0])) {
            LastMinuteOfferFactory::createOne(['venue' => $free[0][1], 'startsOn' => $today->modify('+4 days'), 'endsOn' => $today->modify('+4 days'), 'priceText' => 'Nur mit Pro sichtbar']);
        }
    }

    /**
     * G9: the factory gives every Pro venue a term to 2099; the first three Pro venues by index get real ones instead,
     * so `app:subscriptions:remind` has a 30-day reminder (the dev manager's Pro location, see PortalStory), a 7-day
     * one of a pilot term free of charge (its mail names no price) and a cancelled term to skip, and the CRM and `/konto` show a paid-until day that means something.
     *
     * @param list<Venue> $venues in index order
     */
    private function createTerms(array $venues): void
    {
        $today = new \DateTimeImmutable('today');
        $pro = array_values(array_filter($venues, static fn (Venue $venue): bool => KnownTiers::PRO === $venue->tier()->code()));
        foreach ([20, 5, 60] as $k => $daysLeft) {
            if (!isset($pro[$k])) {
                break;
            }
            foreach (repository(Subscription::class)->findBy(['venue' => $pro[$k]]) as $term) {
                delete($term);
            }
            $end = $today->modify(\sprintf('+%d days', $daysLeft));
            $factory = match ($k) {
                1 => SubscriptionFactory::new()->freeOfCharge('Pilot'),
                2 => SubscriptionFactory::new()->cancelled(),
                default => SubscriptionFactory::new(),
            };
            $factory->create(['venue' => $pro[$k], 'startsOn' => $end->modify('-1 year')->modify('+1 day'), 'endsOn' => $end, 'invoiceRef' => \sprintf('RE-DEMO-%d', $k + 1)]);
        }
    }

    /**
     * G15: venue 1 gets a YouTube and a Vimeo video, venue 3 one YouTube video, so the detail page shows the facade and
     * a Free entry keeps its second video stored and hidden. The ids are fictional: the players say the video does not
     * exist, and no demo entry points at somebody's real video.
     */
    private function createVideos(Venue $first, Venue $third): void
    {
        VenueVideoFactory::createOne(['venue' => $first, 'position' => 1, 'embed' => VenueVideoFactory::youtube('demoVideo01')]);
        VenueVideoFactory::createOne(['venue' => $first, 'position' => 2, 'embed' => VenueVideoFactory::vimeo('999999999999')]);
        VenueVideoFactory::createOne(['venue' => $third, 'position' => 1, 'embed' => VenueVideoFactory::youtube('demoVideo03')]);
    }

    /**
     * G5: an open and a revoked preview link of venue 2, whose last gallery photo has no grant, so the preview (the
     * entry's Vorschau-Links tab shows the link) hides that photo like the public page does.
     */
    private function createPreviewLinks(Venue $venue): void
    {
        VenuePreviewFactory::createOne(['venue' => $venue, 'campaignCode' => 'demo-brief-2026-11']);
        VenuePreviewFactory::new()->revoked()->create(['venue' => $venue, 'campaignCode' => 'demo-mail-2026-10']);
    }

    /**
     * The three complaints of the class docblock, with fictional complainants.
     */
    private function createModerationCases(Venue $held, Venue $kept): void
    {
        $first = static function (Venue $venue): VenuePhoto {
            $photo = VenuePhotoFactory::repository()->findOneBy(['venue' => $venue, 'room' => null, 'position' => 1]);
            \assert($photo instanceof VenuePhoto);

            return $photo;
        };

        $rights = ModerationCaseFactory::createOne([
            'kind' => ModerationKind::Rights,
            'venue' => $held,
            'venueReference' => null,
            'complainantName' => 'Petra Fotografin',
            'complainantEmail' => 'petra.fotografin@example.ch',
            'message' => 'Das erste Foto dieser Location habe ich aufgenommen. Eine Nutzung habe ich nie erlaubt.',
        ]);
        ModerationCasePhotoFactory::createOne(['case' => $rights, 'photo' => $first($held)]);

        $keep = ModerationCaseFactory::new()->decided(ModerationDecision::Keep, note: 'Der Betreiber hat die Rechte belegt.')->create([
            'kind' => ModerationKind::Rights,
            'venue' => $kept,
            'venueReference' => null,
            'complainantName' => 'Max Muster',
            'complainantEmail' => 'max.muster@example.ch',
            'message' => 'Ich glaube, dieses Foto stammt von meiner Website.',
        ]);
        ModerationCasePhotoFactory::createOne(['case' => $keep, 'photo' => $first($kept)]);

        ModerationCaseFactory::createOne();

        // D36: decided more than three years ago, so the nightly retention erased the complainant; the case stays.
        $decidedAt = new \DateTimeImmutable('-3 years -2 months');
        ModerationCaseFactory::new()->decided(ModerationDecision::Keep, note: 'Die Angaben stimmten.', at: $decidedAt)->erased($decidedAt->modify('+3 years -1 day'))->create([
            'kind' => ModerationKind::Correction,
            'venue' => $kept,
            'venueReference' => null,
            'at' => $decidedAt->modify('-2 days'),
        ]);
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
    private function createVenue(Generator $faker, int $i, string $name, string $slug, string $tier, Town $town, array $terms): array
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
        if (self::NO_BRIEFS === $i) {
            // F13: one venue with an inquiry address takes no briefs, so the brief page shows "nur einzeln anfragbar".
            $factory = $factory->afterInstantiate(static fn (Venue $venue) => $venue->changeMultiInquiryConsent(false));
        }
        if (0 !== $i % 3) {
            $factory = $factory->withDistances();
        }
        if (self::UNCHECKED === $i % 5) {
            // G12: every fifth entry's facts are unchecked, so the CMS and the noindex gate show both states.
            $factory = $factory->withUncheckedFacts();
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
        $this->assignTerms($faker, $venue, $terms, 0 === $i % 26);

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
     * The event facts of the even-index venues (see the class docblock). All fictional.
     *
     * @param array<int, array{string, Venue, list<Room>, list<VenueDocument>, VenueTranslation}> $created by index
     * @param array<string, list<TaxonomyTerm>>                                                   $terms
     */
    private function createEventFacts(array $created, array $terms): void
    {
        foreach ($created as $i => [, $venue, , , $translation]) {
            if (0 !== $i % 2) {
                continue;
            }
            $random = new Randomizer(new Mt19937(20261030 + $i));
            $step = intdiv($i, 2);

            $venue->changePriceLevel(PriceLevel::cases()[$step % 3]);
            if (2 === $step % 5) {
                // A stale venue figure under its first room's range (F5b): the guest filter and the card follow the room.
                $venue->changeCapacity(max(10, intdiv($venue->capacityMax(), 3)), null);
            }
            if (0 !== $step % 5) {
                $venue->changeSeatingCapacity(max(1, intdiv($venue->capacityMax() * 2, 3)), $venue->capacityMax());
            }
            if (0 === $step % 3) {
                $venue->changeInstagram('https://www.instagram.com/demo_location_'.$i);
            }
            $translation->describeEvent(
                self::AMBIENCES[$step % \count(self::AMBIENCES)],
                0 === $step % 4 ? 'Mit Bühne und Leinwand sind es etwas weniger Sitzplätze.' : null,
                0 === $step % 3 ? 'Mai bis Oktober, täglich ab 10 Uhr' : null,
            );

            foreach ([TermGroup::Amenity, TermGroup::Catering, TermGroup::Language] as $group) {
                $count = $random->getInt(0, 3);
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
            $this->createPhoto($random, $name, $venue, null, $index + 1, (int) $original, $number, !(self::UNGRANTED_VENUE === $i && array_key_last($gallery) === $index));
        }
        if (0 === $i % 3) {
            foreach ($random->pickArrayKeys($originals, $random->getInt(1, 2)) as $index => $original) {
                $this->createPhoto($random, $name, $venue, $rooms[0], $index + 1, (int) $original, $number);
            }
        }
    }

    private function createPhoto(Randomizer $random, string $name, Venue $venue, ?Room $room, int $position, int $original, int $number, bool $granted = true): void
    {
        \assert($original >= 1 && $original <= DemoMedia::ORIGINALS);
        [$image, $directory] = $this->media->variants($original);
        $photos = $granted ? VenuePhotoFactory::new() : VenuePhotoFactory::new()->withoutGrant();
        $photo = $photos->create([
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
            $largestLayout = true; // The first layout a room offers seats its maximum, which the layouts give the room (F4).
            foreach (Seating::cases() as $seating) {
                if ($faker->boolean(60)) {
                    $seatingMax = $largestLayout ? $roomMax : $faker->numberBetween(max(1, intdiv($roomMax, 3)), $roomMax);
                    $largestLayout = false;
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
     * 1–2 settings and 1–3 buildings (the first of each is the main one), 1–5 event types (only
     * `seminar` is forced, on the three Zurich venues, so `/eventlokale/zuerich/seminar` is a live
     * event page in a canton at the dev threshold, F8), and 0–4 terms of every other filter group. The event groups (amenity,
     * catering, language) belong to createEventFacts().
     *
     * @param array<string, list<TaxonomyTerm>> $terms
     */
    private function assignTerms(Generator $faker, Venue $venue, array $terms, bool $seminar): void
    {
        $picks = [
            'setting' => $faker->randomElements($terms['setting'], $faker->numberBetween(1, 2)),
            'building' => $faker->randomElements($terms['building'], $faker->numberBetween(1, 3)),
            'suitable_for' => $faker->randomElements($terms['suitable_for'], $faker->numberBetween(1, 5)),
        ];
        if ($seminar) {
            $isSeminar = static fn (TaxonomyTerm $term): bool => 'seminar' === $term->code();
            // Seminar first, then the draw without it, still at most five (the tiers' event-type cap).
            $picks['suitable_for'] = \array_slice([
                ...array_filter($terms['suitable_for'], $isSeminar),
                ...array_filter($picks['suitable_for'], static fn (TaxonomyTerm $term): bool => !$isSeminar($term)),
            ], 0, 5);
        }
        foreach (['infrastructure', 'furniture', 'technology', 'surroundings'] as $group) {
            // Furniture has three terms only, and randomElements() refuses to draw more than there are.
            $picks[$group] = $faker->randomElements($terms[$group], min(\count($terms[$group]), $faker->numberBetween(0, 4)));
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
