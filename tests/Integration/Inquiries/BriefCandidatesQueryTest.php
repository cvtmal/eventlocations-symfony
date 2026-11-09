<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Factory\InquiryBriefFactory;
use App\Factory\InquiryFactory;
use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Inquiries\ReadModel\BriefCandidate;
use App\Inquiries\ReadModel\BriefCandidatesQuery;
use App\Inquiries\ValueObject\BriefRules;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * F13: the venues the brief page names, in the order of `nr`; who cannot get any inquiry is left out, who opted out
 * of briefs or reached today's (Zurich) cap is named but not offered.
 */
final class BriefCandidatesQueryTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    /** 00:30 on 10 November in Zurich, still 9 November in UTC. */
    private const NOW = '2026-11-09 23:30:00 UTC';

    private Connection $connection;
    private EntityManagerInterface $em;
    private BriefCandidatesQuery $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $query = $container->get(BriefCandidatesQuery::class);
        self::assertInstanceOf(BriefCandidatesQuery::class, $query);
        $this->query = $query;
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_keeps_the_order_asked_and_names_each_venue(): void
    {
        $a = $this->venue('alpha-saal', 'Alpha Saal');
        $b = $this->venue('beta-haus', 'Beta Haus');
        $c = $this->venue('gamma-hof', 'Gamma Hof');

        $candidates = $this->candidates([$c->number(), $a->number(), $b->number()]);

        self::assertSame([$c->number(), $a->number(), $b->number()], array_map(static fn (BriefCandidate $x): int => $x->number, $candidates));
        self::assertSame(['Gamma Hof', 'Alpha Saal', 'Beta Haus'], array_map(static fn (BriefCandidate $x): string => $x->name, $candidates));
        self::assertSame(['gamma-hof', 'alpha-saal', 'beta-haus'], array_map(static fn (BriefCandidate $x): string => $x->slug, $candidates));
        self::assertSame([$c->id(), $a->id(), $b->id()], array_map(static fn (BriefCandidate $x): string => $x->id, $candidates));
        self::assertSame([true, true, true], array_map(static fn (BriefCandidate $x): bool => $x->offered, $candidates));
    }

    public function test_leaves_out_what_cannot_get_any_inquiry(): void
    {
        $kept = $this->venue('bleibt');
        $unpublished = $this->venue('versteckt', status: VenueStatus::Unpublished);
        $noAddress = VenueFactory::createOne();
        VenueTranslationFactory::createOne(['venue' => $noAddress, 'slug' => 'ohne-adresse']);

        $candidates = $this->candidates([$unpublished->number(), $noAddress->number(), $kept->number(), 999999999]);

        self::assertSame([$kept->number()], array_map(static fn (BriefCandidate $x): int => $x->number, $candidates));
    }

    public function test_an_opted_out_venue_is_named_but_not_offered(): void
    {
        $optedOut = $this->venue('nur-einzeln');
        $optedOut->changeMultiInquiryConsent(false);
        $this->em->flush();
        $open = $this->venue('offen');

        $candidates = $this->candidates([$optedOut->number(), $open->number()]);

        self::assertSame([false, true], array_map(static fn (BriefCandidate $x): bool => $x->offered, $candidates));
    }

    public function test_a_venue_at_todays_cap_is_named_but_not_offered(): void
    {
        $capped = $this->venue('voll');
        $this->briefChildren($capped, BriefRules::DAILY_CAP_PER_VENUE, '2026-11-09 23:10:00 UTC');
        $almost = $this->venue('fast-voll');
        $this->briefChildren($almost, BriefRules::DAILY_CAP_PER_VENUE - 1, '2026-11-09 23:10:00 UTC');

        $candidates = $this->candidates([$capped->number(), $almost->number()]);

        self::assertSame([false, true], array_map(static fn (BriefCandidate $x): bool => $x->offered, $candidates));
    }

    public function test_yesterdays_briefs_and_single_inquiries_do_not_count_to_the_cap(): void
    {
        $venue = $this->venue('gestern-voll');
        // 23:50 on 9 November in Zurich: yesterday, though the same UTC date as now.
        $this->briefChildren($venue, BriefRules::DAILY_CAP_PER_VENUE, '2026-11-09 22:50:00 UTC');
        InquiryFactory::createMany(BriefRules::DAILY_CAP_PER_VENUE, ['venue' => $venue, 'createdAt' => new \DateTimeImmutable('2026-11-09 23:20:00 UTC')]);

        $candidates = $this->candidates([$venue->number()]);

        self::assertCount(1, $candidates);
        self::assertTrue($candidates[0]->offered);
    }

    public function test_no_numbers_ask_nothing(): void
    {
        self::assertSame([], $this->candidates([]));
    }

    /**
     * @param list<int> $numbers
     *
     * @return list<BriefCandidate>
     */
    private function candidates(array $numbers): array
    {
        return $this->query->forNumbers($numbers, 'de', new \DateTimeImmutable(self::NOW));
    }

    private function briefChildren(Venue $venue, int $count, string $at): void
    {
        $createdAt = new \DateTimeImmutable($at);
        $brief = InquiryBriefFactory::createOne(['venueCount' => 2, 'createdAt' => $createdAt]);
        InquiryFactory::createMany($count, ['venue' => $venue, 'brief' => $brief, 'createdAt' => $createdAt]);
    }

    private function venue(string $slug, string $name = 'Seehaus', VenueStatus $status = VenueStatus::Published): Venue
    {
        $venue = VenueFactory::new()->withContact()->create(['name' => $name, 'status' => $status]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => $slug]);

        return $venue;
    }
}
