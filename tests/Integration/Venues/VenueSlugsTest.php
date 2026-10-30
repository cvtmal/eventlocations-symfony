<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\VenueFactory;
use App\Factory\VenueSlugHistoryFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Town;
use App\Geo\Repository\TownRepository;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueTranslation;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\VenueSlugs;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Slug + history (VenueSlugs), shared by the content import and the CMS rename (#24),
 * against app_test in a rolled-back transaction. Fixture names are fictional.
 */
final class VenueSlugsTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private Connection $connection;
    private EntityManagerInterface $em;
    private VenueSlugs $slugs;
    private Town $town;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $slugs = $container->get(VenueSlugs::class);
        $towns = $container->get(TownRepository::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(VenueSlugs::class, $slugs);
        self::assertInstanceOf(TownRepository::class, $towns);
        $this->em = $em;
        $this->slugs = $slugs;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->town = $towns->findByZipAndName('8001', 'Zürich')[0];
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_a_new_translation_gets_the_name_slug_and_no_history(): void
    {
        $venue = $this->venue('Schloss Probehausen');

        self::assertSame('schloss-probehausen', $this->slugs->assign($venue, 'de', 'Schloss Probehausen', $this->town, null, true));
        $this->em->flush();

        self::assertSame([], $this->history($venue));
    }

    public function test_an_unchanged_name_keeps_the_current_slug_and_writes_nothing(): void
    {
        $venue = $this->venue('Schloss Probehausen');
        $current = $this->translation($venue, 'ein-frueherer-slug');

        self::assertSame('ein-frueherer-slug', $this->slugs->assign($venue, 'de', 'Schloss Probehausen', $this->town, $current, false));
        $this->em->flush();

        self::assertSame([], $this->history($venue));
    }

    public function test_a_rename_moves_the_old_slug_to_the_history_and_a_rename_back_takes_it_back(): void
    {
        $venue = $this->venue('Schloss Probehausen');
        $current = $this->translation($venue, 'schloss-probehausen');

        $this->rename($venue, $current, 'Schloss Neuprobe');
        self::assertSame('schloss-neuprobe', $current->slug());
        self::assertSame(['schloss-probehausen'], $this->history($venue));

        $this->rename($venue, $current, 'Schloss Probehausen');
        self::assertSame('schloss-probehausen', $current->slug());
        self::assertSame(['schloss-neuprobe'], $this->history($venue), 'the own history row of the slug taken back is deleted');
    }

    public function test_a_slug_in_another_venues_history_is_taken(): void
    {
        $other = $this->venue('Seehaus Andersprobe');
        VenueSlugHistoryFactory::createOne(['venue' => $other, 'locale' => 'de', 'slug' => 'seehaus-probehausen']);
        $venue = $this->venue('Seehaus Probehausen');

        self::assertSame('seehaus-probehausen-zuerich', $this->slugs->assign($venue, 'de', 'Seehaus Probehausen', $this->town, null, true));
    }

    public function test_a_slug_typed_by_hand_moves_the_old_one_to_the_history(): void
    {
        $venue = $this->venue('Schloss Probehausen');
        $current = $this->translation($venue, 'schloss-probehausen');

        $this->slugs->change($venue, $current, 'schloss-am-probesee');
        $this->em->flush();

        self::assertSame('schloss-am-probesee', $current->slug());
        self::assertSame(['schloss-probehausen'], $this->history($venue));
    }

    public function test_the_current_slug_typed_again_writes_nothing(): void
    {
        $venue = $this->venue('Schloss Probehausen');
        $current = $this->translation($venue, 'schloss-probehausen');

        $this->slugs->change($venue, $current, 'schloss-probehausen');
        $this->em->flush();

        self::assertSame([], $this->history($venue));
    }

    public function test_an_own_history_slug_is_taken_back_and_its_row_deleted(): void
    {
        $venue = $this->venue('Schloss Probehausen');
        $current = $this->translation($venue, 'schloss-am-probesee');
        VenueSlugHistoryFactory::createOne(['venue' => $venue, 'locale' => 'de', 'slug' => 'schloss-probehausen']);

        $this->slugs->change($venue, $current, 'schloss-probehausen');
        $this->em->flush();

        self::assertSame('schloss-probehausen', $current->slug());
        self::assertSame(['schloss-am-probesee'], $this->history($venue));
    }

    public function test_another_venues_current_slug_is_refused(): void
    {
        $this->translation($this->venue('Seehaus Andersprobe'), 'seehaus-andersprobe');
        $venue = $this->venue('Schloss Probehausen');
        $current = $this->translation($venue, 'schloss-probehausen');

        $this->assertSlugTaken($venue, $current, 'seehaus-andersprobe');
    }

    public function test_another_venues_history_slug_is_refused(): void
    {
        VenueSlugHistoryFactory::createOne(['venue' => $this->venue('Seehaus Andersprobe'), 'locale' => 'de', 'slug' => 'seehaus-alt']);
        $venue = $this->venue('Schloss Probehausen');
        $current = $this->translation($venue, 'schloss-probehausen');

        $this->assertSlugTaken($venue, $current, 'seehaus-alt');
    }

    private function assertSlugTaken(Venue $venue, VenueTranslation $current, string $slug): void
    {
        try {
            $this->slugs->change($venue, $current, $slug);
            self::fail('The slug should be refused.');
        } catch (VenueRuleViolation $violation) {
            self::assertSame('venue.error.slug_taken', $violation->translationKey());
            self::assertSame('slug', $violation->field);
        }
        self::assertSame('schloss-probehausen', $current->slug());
        self::assertSame([], $this->history($venue));
    }

    private function venue(string $name): Venue
    {
        return VenueFactory::createOne(['name' => $name, 'town' => $this->town]);
    }

    private function translation(Venue $venue, string $slug): VenueTranslation
    {
        return VenueTranslationFactory::createOne(['venue' => $venue, 'locale' => 'de', 'slug' => $slug]);
    }

    private function rename(Venue $venue, VenueTranslation $current, string $name): void
    {
        $slug = $this->slugs->assign($venue, 'de', $name, $this->town, $current, true);
        $current->revise($slug, $current->summary(), $current->description(), $current->metaDescription(), $current->parkingText());
        $this->em->flush();
    }

    /**
     * @return list<string>
     */
    private function history(Venue $venue): array
    {
        /* @var list<string> */
        return $this->connection->fetchFirstColumn('SELECT slug FROM venue_slug_history WHERE venue_id = ? ORDER BY slug', [$venue->id()]);
    }
}
