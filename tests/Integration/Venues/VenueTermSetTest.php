<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\VenueFactory;
use App\Factory\VenueTermFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Venues\Entity\Venue;
use App\Venues\Repository\TaxonomyTermRepository;
use App\Venues\ValueObject\TermGroup;
use App\Venues\VenueTermSet;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * VenueTermSet (#24): group-by-group replacement of a venue's terms, against app_test in a
 * rolled-back transaction.
 */
final class VenueTermSetTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private Connection $connection;
    private EntityManagerInterface $em;
    private VenueTermSet $set;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $terms = $container->get(TaxonomyTermRepository::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(TaxonomyTermRepository::class, $terms);
        $this->em = $em;
        // Built by hand: no service uses it before Phase 4, so the container drops it.
        $this->set = new VenueTermSet($em, $terms);
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_only_the_listed_groups_change(): void
    {
        $venue = $this->venue([['building', 'cafe', true], ['setting', 'lake', true], ['ceremony', 'chapel', false]]);

        $this->replace($venue, ['ceremony' => ['civil_ceremony', 'free_ceremony']], []);

        self::assertSame(['building:cafe*', 'ceremony:civil_ceremony', 'ceremony:free_ceremony', 'setting:lake*'], $this->terms($venue));
    }

    public function test_the_main_term_changes(): void
    {
        $venue = $this->venue([['building', 'cafe', true], ['building', 'casino', false]]);

        $this->replace($venue, ['building' => ['cafe', 'casino']], ['building' => 'casino']);

        self::assertSame(['building:cafe', 'building:casino*'], $this->terms($venue));
    }

    public function test_a_null_main_clears_it(): void
    {
        $venue = $this->venue([['setting', 'lake', true]]);

        $this->replace($venue, ['setting' => ['lake', 'city']], ['setting' => null]);

        self::assertSame(['setting:city', 'setting:lake'], $this->terms($venue));
    }

    public function test_a_group_without_main_input_keeps_its_main(): void
    {
        $venue = $this->venue([['suitable_for', 'wedding', true], ['suitable_for', 'birthday', false]]);

        $this->replace($venue, ['suitable_for' => ['wedding', 'apero']], []);

        self::assertSame(['suitable_for:apero', 'suitable_for:wedding*'], $this->terms($venue));
    }

    public function test_a_removed_term_loses_its_main(): void
    {
        $venue = $this->venue([['suitable_for', 'wedding', true], ['suitable_for', 'birthday', false]]);

        $this->replace($venue, ['suitable_for' => ['birthday']], []);

        self::assertSame(['suitable_for:birthday'], $this->terms($venue));
    }

    public function test_an_empty_group_removes_its_terms(): void
    {
        $venue = $this->venue([['building', 'cafe', true], ['setting', 'lake', false]]);

        $this->replace($venue, ['building' => []], ['building' => null]);

        self::assertSame(['setting:lake'], $this->terms($venue));
    }

    public function test_an_unknown_code_is_refused(): void
    {
        $venue = $this->venue([]);

        $this->expectException(\InvalidArgumentException::class);

        $this->set->replace($venue, ['building' => ['igloo']], []);
    }

    /**
     * @param list<array{string, string, bool}> $terms group, code, main
     */
    private function venue(array $terms): Venue
    {
        $venue = VenueFactory::createOne();
        foreach ($terms as [$group, $code, $main]) {
            VenueTermFactory::new()->term(TermGroup::from($group), $code)->create(['venue' => $venue, 'isMain' => $main]);
        }

        return $venue;
    }

    /**
     * @param array<string, list<string>> $codes
     * @param array<string, string|null>  $mains
     */
    private function replace(Venue $venue, array $codes, array $mains): void
    {
        $this->set->replace($venue, $codes, $mains);
        $this->em->flush();
    }

    /**
     * @return list<string> `group:code`, `*` for the main term, sorted
     */
    private function terms(Venue $venue): array
    {
        /* @var list<string> */
        return $this->connection->fetchFirstColumn(
            "SELECT term_group || ':' || term_code || CASE WHEN is_main THEN '*' ELSE '' END FROM venue_terms WHERE venue_id = ? ORDER BY 1",
            [$venue->id()],
        );
    }
}
