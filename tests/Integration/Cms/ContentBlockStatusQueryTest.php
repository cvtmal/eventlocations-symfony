<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cms;

use App\Cms\ReadModel\ContentBlockStatusQuery;
use App\Cms\ValueObject\ContentVersion;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The read side of #26's back-office screens, against app_test in a rolled-back transaction.
 */
final class ContentBlockStatusQueryTest extends KernelTestCase
{
    private Connection $connection;
    private ContentBlockStatusQuery $query;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->query = new ContentBlockStatusQuery($connection);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_a_landing_page_is_one_subject_with_its_newest_change(): void
    {
        $this->insert('landing.canton.zh.intro', '2026-10-01 08:00:00+00');
        $this->insert('landing.canton.zh.title', '2026-10-03 08:00:00+00');
        $this->insert('landing.town.0199f0d0-0000-7000-8000-000000000001.title', '2026-10-02 08:00:00+00');
        $this->insert('landing.building.brewery_winery.description', '2026-10-04 08:00:00+00');
        $this->insert('listing.intro', '2026-10-05 08:00:00+00');

        $changes = $this->query->byPrefix('landing.');

        self::assertSame('2026-10-04 08:00', $this->utc($changes->of('landing.building.brewery_winery')));
        self::assertSame('2026-10-03 08:00', $this->utc($changes->of('landing.canton.zh')));
        self::assertSame('2026-10-02 08:00', $this->utc($changes->of('landing.town.0199f0d0-0000-7000-8000-000000000001')));
        self::assertNull($changes->of('landing.canton.zh.title'), 'A landing page is one subject, not three.');
        self::assertNull($changes->of('listing.intro'), 'The prefix limits the subjects.');
        self::assertSame('2026-10-05 08:00', $this->utc($this->query->byPrefix('listing.')->of('listing.intro')));
        self::assertNull($this->query->byPrefix('landing.canton.z_')->of('landing.canton.zh'), 'The prefix is text, no LIKE pattern.');
    }

    public function test_bodies_and_version_cover_only_the_given_keys(): void
    {
        $this->insert('listing.intro', '2026-10-05 08:00:00.123456+00', 'Willkommen.');
        $this->insert('footer.note', '2026-10-05 08:00:00+00', 'Fussnote.');

        $bodies = $this->query->bodies(['listing.intro']);

        self::assertSame('Willkommen.', $bodies->of('de', 'listing.intro'));
        self::assertNull($bodies->of('de', 'footer.note'), 'Only the given keys are read.');
        self::assertNull($bodies->of('fr', 'listing.intro'));
        self::assertNull($this->query->bodies([])->of('de', 'listing.intro'));
        self::assertSame(
            ContentVersion::of([['key' => 'listing.intro', 'locale' => 'de', 'updated_at' => '2026-10-05 08:00:00+00', 'body_markdown' => 'Willkommen.']]),
            $this->query->versionOf(['listing.intro', 'listing.missing']),
        );
        self::assertSame(ContentVersion::of([]), $this->query->versionOf(['landing.canton.zh.title']));
        self::assertSame(ContentVersion::of([]), $this->query->versionOf([]));
    }

    private function utc(?\DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i');
    }

    private function insert(string $key, string $updatedAt, string $body = 'Text.'): void
    {
        $this->connection->insert('content_blocks', ['key' => $key, 'locale' => 'de', 'body_markdown' => $body, 'updated_at' => $updatedAt]);
    }
}
