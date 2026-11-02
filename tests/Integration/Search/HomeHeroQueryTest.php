<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenuePhotoTranslationFactory;
use App\Factory\VenueTranslationFactory;
use App\Search\ReadModel\HomeHero;
use App\Search\ReadModel\HomeHeroQuery;
use App\Search\ReadModel\ListingQuery;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\TranslationJoin;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

/**
 * Q18's hero photo: found by the hash of its original file on a published venue, and the
 * fallback to the first Spotlight card with a photo. Each test runs in a rolled-back transaction.
 */
final class HomeHeroQueryTest extends KernelTestCase
{
    use Factories;

    private const HASH = 'cfbf9375b66ff088996ab8a64d99552e0caac0dcd0ca86189a54e4858c8b96d1';
    private const PATH = 'content/some-key/'.self::HASH.'.jpg';

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_the_photo_of_a_published_venue_is_found_by_its_hash(): void
    {
        $venue = $this->venue('Am See');
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2, 'originalPath' => self::PATH]);

        $hero = $this->query()->photo(self::HASH, 'de');

        self::assertNotNull($hero);
        self::assertSame('Am See', $hero->venueName);
        self::assertSame($photo->id(), $hero->photo->photoId);
        self::assertSame($venue->number(), $hero->photo->venueNumber);
        self::assertSame(self::PATH, $hero->photo->originalPath);
        self::assertTrue($hero->photo->hasVariants());
        self::assertNull($hero->photo->alt);
    }

    public function test_the_localized_alt_arrives(): void
    {
        $photo = VenuePhotoFactory::createOne(['venue' => $this->venue('Am See'), 'originalPath' => self::PATH]);
        VenuePhotoTranslationFactory::createOne(['photo' => $photo, 'alt' => 'Seeterrasse im Abendlicht']);

        self::assertSame('Seeterrasse im Abendlicht', $this->query()->photo(self::HASH, 'de')?->photo->alt);
    }

    public function test_an_unpublished_venue_gives_no_photo(): void
    {
        VenuePhotoFactory::createOne(['venue' => $this->venue('Entwurf', ['status' => VenueStatus::Unpublished]), 'originalPath' => self::PATH]);

        self::assertNull($this->query()->photo(self::HASH, 'de'));
    }

    public function test_a_room_photo_gives_no_photo(): void
    {
        $venue = $this->venue('Am See');
        VenuePhotoFactory::createOne(['venue' => $venue, 'room' => RoomFactory::createOne(['venue' => $venue]), 'originalPath' => self::PATH]);

        self::assertNull($this->query()->photo(self::HASH, 'de'));
    }

    public function test_an_unknown_hash_gives_no_photo(): void
    {
        VenuePhotoFactory::createOne(['venue' => $this->venue('Am See'), 'originalPath' => self::PATH]);

        self::assertNull($this->query()->photo(str_repeat('0', 64), 'de'));
    }

    public function test_the_fallback_skips_a_card_without_a_photo(): void
    {
        $this->venue('Ohne Foto', ['tier' => Tier::BeliebteLocation]);
        $photo = VenuePhotoFactory::createOne(['venue' => $this->venue('Mit Foto', ['tier' => Tier::Basic])]);

        $cards = (new ListingQuery($this->connection, $this->translationJoin(), $this->locale(), new MockClock('2026-10-30 10:00:00', 'UTC')))->first(3, 1, 'de');
        self::assertSame('Ohne Foto', $cards[0]->name);

        $hero = HomeHero::fromCards($cards);

        self::assertNotNull($hero);
        self::assertSame('Mit Foto', $hero->venueName);
        self::assertSame($photo->id(), $hero->photo->photoId);
    }

    public function test_no_card_with_a_photo_gives_no_fallback(): void
    {
        self::assertNull(HomeHero::fromCards([]));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function venue(string $name, array $attributes = []): Venue
    {
        $venue = VenueFactory::createOne(['name' => $name] + $attributes);
        VenueTranslationFactory::createOne(['venue' => $venue]);

        return $venue;
    }

    private function query(): HomeHeroQuery
    {
        return new HomeHeroQuery($this->connection, $this->translationJoin(), $this->locale());
    }

    private function translationJoin(): TranslationJoin
    {
        return new TranslationJoin($this->locale());
    }

    private function locale(): EnabledLocale
    {
        $locale = self::getContainer()->get(EnabledLocale::class);
        self::assertInstanceOf(EnabledLocale::class, $locale);

        return $locale;
    }
}
