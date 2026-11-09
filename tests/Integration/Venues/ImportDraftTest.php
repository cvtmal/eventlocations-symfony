<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\PortalUserFactory;
use App\Venues\Command\CheckVenueFacts;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Zenstruck\Foundry\Test\Factories;

/**
 * A draft folder (status unpublished) may come without texts (F14): the import makes an
 * unpublished venue with a default-locale row, an empty summary and the slug from the name,
 * as CreateVenue does; a summary written later fills that row, and dropping the texts again
 * keeps it. The draft rule (F14, D54): a folder asking `published` for an entry nobody manages
 * goes online only with checked facts and a description.
 */
final class ImportDraftTest extends KernelTestCase
{
    use DispatchesVenueCommands, RunsContentImport {
        RunsContentImport::purger insteadof DispatchesVenueCommands;
    }
    use Factories;

    public function test_a_draft_without_texts_imports_with_an_empty_default_row(): void
    {
        $this->content->venue('remise-entwurf', ['status' => 'unpublished', 'translations' => null]);

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertSame('unpublished', $this->venueRow('remise-entwurf')['status'] ?? null);
        self::assertSame(
            [['locale' => 'de', 'slug' => 'gasthaus-remise-entwurf', 'summary' => '', 'description' => null]],
            $this->translations('remise-entwurf'),
        );

        $this->content->change('remise-entwurf', ['translations' => ['de' => ['summary' => 'Fiktive Remise am Bach.', 'description' => 'Lang.']]]);
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        self::assertSame(
            [['locale' => 'de', 'slug' => 'gasthaus-remise-entwurf', 'summary' => 'Fiktive Remise am Bach.', 'description' => 'Lang.']],
            $this->translations('remise-entwurf'),
        );

        $this->content->change('remise-entwurf', ['translations' => null]);
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        self::assertSame(
            [['locale' => 'de', 'slug' => 'gasthaus-remise-entwurf', 'summary' => '', 'description' => null]],
            $this->translations('remise-entwurf'),
            'the default-locale row stays, emptied as the folder says',
        );
    }

    public function test_a_folder_without_texts_and_without_draft_status_fails(): void
    {
        $this->content->venue('remise-ohne-text', ['translations' => null]);

        self::assertSame(Command::FAILURE, $this->import(), $this->display());
        self::assertStringContainsString('translations', $this->display());
        self::assertFalse($this->venueRow('remise-ohne-text'));
    }

    /**
     * D54 pin: the import refuses to publish an unmanaged entry without «Fakten geprüft» and a description. Facts
     * unchecked keeps it a draft, so does a missing description once the facts are checked; with both it goes online.
     */
    public function test_the_import_refuses_to_publish_an_unmanaged_entry_without_checked_facts_and_a_description(): void
    {
        $this->content->venue('remise-am-teich', ['translations' => ['de' => ['description' => 'Eine fiktive Remise am Teich.']]]);

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        self::assertSame('unpublished', $this->venueRow('remise-am-teich')['status'] ?? null, 'facts unchecked');
        self::assertStringContainsString('remise-am-teich: facts', $this->display());

        $this->dispatch(new CheckVenueFacts($this->venueNumber('remise-am-teich'), true));
        $this->content->change('remise-am-teich', ['translations' => ['de' => ['description' => null]]]);
        self::assertSame(Command::SUCCESS, $this->import(['--only' => 'remise-am-teich']), $this->display());
        self::assertSame('unpublished', $this->venueRow('remise-am-teich')['status'] ?? null, 'description missing');
        self::assertStringContainsString('remise-am-teich: description', $this->display());

        $this->content->change('remise-am-teich', ['translations' => ['de' => ['description' => 'Eine fiktive Remise am Teich.']]]);
        self::assertSame(Command::SUCCESS, $this->import(['--only' => 'remise-am-teich']), $this->display());
        self::assertSame('published', $this->venueRow('remise-am-teich')['status'] ?? null);
        self::assertStringContainsString('Held as draft: 0.', $this->display());
    }

    public function test_the_rule_never_takes_an_online_entry_offline(): void
    {
        $this->content->venue('remise-online');
        $this->import();
        $this->connection->executeStatement("UPDATE venues SET status = 'published' WHERE content_key = 'remise-online'");

        $this->content->change('remise-online', ['capacity' => ['max' => 150]]);
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertSame('published', $this->venueRow('remise-online')['status'] ?? null);
        self::assertSame(150, $this->venueRow('remise-online')['capacity_max'] ?? null);
        self::assertStringContainsString('Held as draft: 0.', $this->display());
    }

    public function test_an_entry_with_an_active_manager_is_not_held(): void
    {
        $this->content->venue('remise-betreut');
        $this->import();
        $user = PortalUserFactory::createOne();
        $this->connection->executeStatement(
            "INSERT INTO portal_venue_access (portal_user_id, venue_id, status, confirmed_at) VALUES (?, ?, 'active', now())",
            [$user->id(), $this->venueId('remise-betreut')],
        );

        self::assertSame(Command::SUCCESS, $this->import(['--force' => true]), $this->display());

        self::assertSame('published', $this->venueRow('remise-betreut')['status'] ?? null, 'G12: online, noindex until checked');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function translations(string $key): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT t.locale, t.slug, t.summary, t.description FROM venue_translations t JOIN venues v ON v.id = t.venue_id WHERE v.content_key = ? ORDER BY t.locale',
            [$key],
        );
    }
}
