<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\ModerationCaseFactory;
use App\Factory\ModerationCasePhotoFactory;
use App\Factory\RoomFactory;
use App\Factory\RoomTranslationFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ReadModel\ModerationCaseQuery;
use App\Venues\ReadModel\ModerationCaseRow;
use App\Venues\ReadModel\ReportablePhoto;
use App\Venues\ReadModel\ReportableVenueQuery;
use App\Venues\ValueObject\ModerationDecision;
use App\Venues\ValueObject\ModerationKind;
use App\Venues\ValueObject\ModerationStatus;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The two read models of G2 against `app_test`: the venue of the public complaint form (published only, the public
 * photos only) and the staff queue (order, held count, one case with its photos).
 */
final class ModerationReadModelsTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase;

    public function test_the_form_knows_only_a_published_venue(): void
    {
        $draft = $this->venue('Entwurfshaus', 'entwurfshaus', VenueStatus::Unpublished);

        self::assertNull($this->reportable()->find($draft->number(), 'de'));
        self::assertNull($this->reportable()->find(0, 'de'));
        self::assertNull($this->reportable()->find(2147483648, 'de'));
        self::assertNull($this->reportable()->find(99999, 'de'));
    }

    public function test_the_form_offers_the_public_photos_gallery_first_then_the_rooms(): void
    {
        $venue = $this->venue('Seehaus Formular', 'seehaus-formular');
        $gallery = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 2]);
        $held = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 3]);
        $room = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        RoomTranslationFactory::createOne(['room' => $room, 'name' => 'Saal']);
        $roomPhoto = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room, 'position' => 1]);
        ModerationCasePhotoFactory::createOne(['case' => ModerationCaseFactory::createOne(['venue' => $venue, 'venueReference' => null]), 'photo' => $held]);

        $found = $this->reportable()->find($venue->number(), 'de');

        self::assertNotNull($found);
        self::assertSame(['Seehaus Formular', 'seehaus-formular', $venue->number()], [$found->name, $found->slug, $found->number]);
        self::assertSame([$gallery->id(), $roomPhoto->id()], $found->photoIds(), 'neither the ungranted nor the held photo');
        self::assertSame([[1, null], [1, 'Saal']], array_map(static fn (ReportablePhoto $photo): array => [$photo->position, $photo->roomName], $found->photos));
    }

    public function test_the_queue_lists_open_cases_oldest_first_and_decided_ones_newest_first(): void
    {
        $venue = $this->venue('Seehaus Queue', 'seehaus-queue');
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $older = ModerationCaseFactory::createOne(['kind' => ModerationKind::Rights, 'venue' => $venue, 'venueReference' => null, 'at' => new \DateTimeImmutable('2026-11-01 10:00 UTC')]);
        ModerationCasePhotoFactory::createOne(['case' => $older, 'photo' => $photo]);
        $newer = ModerationCaseFactory::createOne(['at' => new \DateTimeImmutable('2026-11-03 10:00 UTC')]);
        $first = ModerationCaseFactory::new()->decided(ModerationDecision::Remove)->create(['at' => new \DateTimeImmutable('2026-10-01 10:00 UTC')]);
        $second = ModerationCaseFactory::new()->decided()->create(['at' => new \DateTimeImmutable('2026-10-02 10:00 UTC')]);
        $this->connection->executeStatement("UPDATE moderation_cases SET decided_at = '2026-11-05 10:00+00' WHERE id = ?", [$first->id()]);
        $this->connection->executeStatement("UPDATE moderation_cases SET decided_at = '2026-11-04 10:00+00' WHERE id = ?", [$second->id()]);

        $open = $this->queue()->open();
        $decided = $this->queue()->decided(10);

        self::assertSame([$older->id(), $newer->id()], array_map(static fn (ModerationCaseRow $row): string => $row->id, $open));
        self::assertSame([$venue->number(), 'Seehaus Queue', null, 1], [$open[0]->venueNumber, $open[0]->venueName, $open[0]->venueReference, $open[0]->heldPhotos]);
        self::assertSame([null, 'Gasthaus Beispiel, Musterdorf', 0], [$open[1]->venueNumber, $open[1]->venueReference, $open[1]->heldPhotos]);
        self::assertSame([$first->id(), $second->id()], array_map(static fn (ModerationCaseRow $row): string => $row->id, $decided));
        self::assertSame([ModerationStatus::Decided, ModerationDecision::Remove], [$decided[0]->status, $decided[0]->decision]);
        self::assertCount(1, $this->queue()->decided(1));
    }

    public function test_one_case_with_the_photos_it_names_present_or_removed(): void
    {
        $venue = $this->venue('Seehaus Fall', 'seehaus-fall');
        $present = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1, 'originalPath' => 'demo/present.jpg']);
        $removed = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2, 'originalPath' => 'demo/removed.jpg']);
        $case = ModerationCaseFactory::createOne(['kind' => ModerationKind::Rights, 'venue' => $venue, 'venueReference' => null, 'sourceUrl' => '/eventlokal/seehaus-fall']);
        ModerationCasePhotoFactory::createOne(['case' => $case, 'photo' => $present]);
        ModerationCasePhotoFactory::createOne(['case' => $case, 'photo' => $removed]);
        $this->connection->executeStatement('DELETE FROM venue_photos WHERE id = ?', [$removed->id()]);

        $view = $this->queue()->find($case->id());

        self::assertNotNull($view);
        self::assertSame([ModerationKind::Rights, $venue->number(), 'Seehaus Fall', true, '/eventlokal/seehaus-fall'], [$view->kind, $view->venueNumber, $view->venueName, $view->venuePublished, $view->sourceUrl]);
        self::assertSame(['Erika Beispiel', 'erika.beispiel@example.ch'], [$view->complainantName, $view->complainantEmail]);
        self::assertSame([$present->id(), null], array_map(static fn ($photo): ?string => $photo->picture?->photoId, $view->photos));
        self::assertSame(['demo/present.jpg', 'demo/removed.jpg'], array_map(static fn ($photo): string => $photo->originalPath, $view->photos));
        self::assertSame($venue->number(), $view->photos[0]->picture?->venueNumber);
        self::assertSame(1, $view->heldPhotos());
        self::assertTrue($view->isOpen());
        self::assertTrue($view->canTakeEntryOffline());
        self::assertNull($this->queue()->find('019a0000-0000-7000-8000-000000000999'));
        self::assertNull($this->queue()->find('kein-uuid'));
    }

    private function venue(string $name, string $slug, VenueStatus $status = VenueStatus::Published): Venue
    {
        $venue = VenueFactory::createOne(['name' => $name, 'status' => $status, 'town' => DetailVenues::town('Zürich')]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => $slug]);

        return $venue;
    }

    private function reportable(): ReportableVenueQuery
    {
        $query = self::getContainer()->get(ReportableVenueQuery::class);
        self::assertInstanceOf(ReportableVenueQuery::class, $query);

        return $query;
    }

    private function queue(): ModerationCaseQuery
    {
        $query = self::getContainer()->get(ModerationCaseQuery::class);
        self::assertInstanceOf(ModerationCaseQuery::class, $query);

        return $query;
    }
}
