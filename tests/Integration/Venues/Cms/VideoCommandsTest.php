<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues\Cms;

use App\Factory\VenueFactory;
use App\Factory\VenueVideoFactory;
use App\Tests\Integration\Venues\DispatchesVenueCommands;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Command\AddVenueVideo;
use App\Venues\Command\RemoveVenueVideo;
use App\Venues\Command\ReorderVenueVideos;
use App\Venues\Exception\StaleEdit;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\ValueObject\KnownTiers;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Zenstruck\Foundry\Test\Factories;

/**
 * AddVenueVideo, RemoveVenueVideo, ReorderVenueVideos (G15): only provider and id are stored, a link outside the
 * allowlist, the same video again and one above the plan's `videos_max` (F12) are refused and write nothing, and every
 * write purges the venue (not the listings: videos are not on the cards).
 */
final class VideoCommandsTest extends KernelTestCase
{
    use DispatchesVenueCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_a_link_becomes_provider_and_id_at_the_next_position_and_purges_the_venue_only(): void
    {
        $venue = VenueFactory::createOne(['tier' => KnownTiers::PRO]);
        VenueVideoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $this->em->clear();

        $id = $this->dispatch(new AddVenueVideo($venue->number(), 'https://vimeo.com/76979871?share=copy'));

        self::assertIsString($id);
        self::assertSame(
            ['position' => 2, 'provider' => 'vimeo', 'video_id' => '76979871'],
            $this->connection->fetchAssociative('SELECT position, provider, video_id FROM venue_videos WHERE id = ?', [$id]),
        );
        self::assertNotNull($this->connection->fetchOne('SELECT cms_edited_at FROM venues WHERE id = ?', [$venue->id()]));
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(0, $this->purger()->listings);
        // The audit keeps provider and id, never the pasted link with its tracking parameters.
        $audit = $this->auditRows($venue->number());
        self::assertSame(['provider' => 'vimeo', 'provider_video_id' => '76979871'], end($audit)['changes'] ?? null);
    }

    public function test_a_link_outside_the_allowlist_is_refused_and_writes_nothing(): void
    {
        $venue = VenueFactory::createOne(['tier' => KnownTiers::PRO]);

        foreach (['https://example.com/video.mp4', '<iframe src="https://www.youtube.com/embed/aB3_dE-fGh1"></iframe>', 'https://youtube.com.example.com/watch?v=aB3_dE-fGh1'] as $url) {
            $violation = $this->violation(new AddVenueVideo($venue->number(), $url));
            self::assertSame('videos.error.not_allowed', $violation->translationKey());
            self::assertSame('url', $violation->field);
        }
        self::assertSame(0, $this->videoCount($venue->id()));
        self::assertSame([], $this->purger()->venues);
    }

    public function test_the_same_video_again_is_refused_in_any_link_form(): void
    {
        $venue = VenueFactory::createOne(['tier' => KnownTiers::PRO]);
        $this->dispatch(new AddVenueVideo($venue->number(), 'https://www.youtube.com/watch?v=aB3_dE-fGh1'));
        $this->em->clear();

        $violation = $this->violation(new AddVenueVideo($venue->number(), 'youtu.be/aB3_dE-fGh1'));

        self::assertSame('videos.error.already_added', $violation->translationKey());
        self::assertSame(1, $this->videoCount($venue->id()));
    }

    /**
     * F12: Free holds one video; the message names the plan and the limit.
     */
    public function test_a_second_video_is_refused_on_free(): void
    {
        $venue = VenueFactory::createOne(['tier' => KnownTiers::FREE]);
        VenueVideoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $this->em->clear();

        $violation = $this->violation(new AddVenueVideo($venue->number(), 'https://vimeo.com/76979871'));

        self::assertSame('videos.error.too_many', $violation->translationKey());
        self::assertSame(['max' => 1, 'tier' => 'Free'], $violation->parameters);
        self::assertSame(1, $this->videoCount($venue->id()));
    }

    public function test_remove_closes_the_gap_and_reorder_follows_the_list(): void
    {
        $venue = VenueFactory::createOne(['tier' => KnownTiers::PRO]);
        $ids = [];
        foreach ([1, 2, 3] as $position) {
            $ids[] = VenueVideoFactory::createOne(['venue' => $venue, 'position' => $position])->id();
        }
        $this->em->clear();

        $this->dispatch(new RemoveVenueVideo($venue->number(), $ids[0]));
        self::assertSame([$ids[1], $ids[2]], $this->order($venue->id()));
        $this->em->clear();
        $this->dispatch(new ReorderVenueVideos($venue->number(), [$ids[2], $ids[1]]));
        self::assertSame([$ids[2], $ids[1]], $this->order($venue->id()));
        self::assertSame([$venue->number(), $venue->number()], $this->purger()->venues);
        self::assertSame(0, $this->purger()->listings);
    }

    public function test_a_video_of_another_venue_or_an_incomplete_order_changes_nothing(): void
    {
        $venue = VenueFactory::createOne(['tier' => KnownTiers::PRO]);
        $own = VenueVideoFactory::createOne(['venue' => $venue, 'position' => 1])->id();
        VenueVideoFactory::createOne(['venue' => $venue, 'position' => 2]);
        $foreign = VenueVideoFactory::createOne()->id();
        $this->em->clear();

        self::assertInstanceOf(VenueRowNotFound::class, $this->failure(new RemoveVenueVideo($venue->number(), $foreign)));
        self::assertInstanceOf(VenueRowNotFound::class, $this->failure(new RemoveVenueVideo($venue->number(), 'not-a-uuid')));
        self::assertInstanceOf(StaleEdit::class, $this->failure(new ReorderVenueVideos($venue->number(), [$own])));
        self::assertSame(2, $this->videoCount($venue->id()));
        self::assertSame([], $this->purger()->venues);
    }

    public function test_the_database_refuses_an_id_of_the_wrong_shape(): void
    {
        $venue = VenueFactory::createOne();

        $this->expectException(DriverException::class);
        $this->connection->executeStatement(
            "INSERT INTO venue_videos (id, venue_id, position, provider, video_id) VALUES (gen_random_uuid(), ?, 1, 'youtube', 'x\"><script>')",
            [$venue->id()],
        );
    }

    private function failure(object $command): \Throwable
    {
        try {
            $this->dispatch($command);
        } catch (HandlerFailedException $failure) {
            return $failure->getPrevious() ?? $failure;
        }
        self::fail('The command should have failed.');
    }

    private function videoCount(string $venueId): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM venue_videos WHERE venue_id = ?', [$venueId]);
    }

    /**
     * @return list<string>
     */
    private function order(string $venueId): array
    {
        /* @var list<string> */
        return $this->connection->fetchFirstColumn('SELECT id FROM venue_videos WHERE venue_id = ? ORDER BY position', [$venueId]);
    }
}
