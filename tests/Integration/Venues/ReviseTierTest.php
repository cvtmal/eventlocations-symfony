<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Cms\Form\TierData;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Command\ReviseTier;
use App\Venues\EntitlementResolver;
use App\Venues\Exception\TierNameTaken;
use App\Venues\Exception\TierNotFound;
use App\Venues\ReadModel\TierDefinitionsQuery;
use App\Venues\ValueObject\KnownTiers;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * The staff edit of a tier through command.bus (F12): the row is revised, the listings cache is cleared once and
 * after the commit, the audit row names the fields and not the values, and a save that changes nothing, names a
 * code no tier has or takes another tier's name leaves no trace. Rolled back with the test, so the seeded Free and
 * Pro rows stay as they were.
 */
final class ReviseTierTest extends KernelTestCase
{
    use DispatchesVenueCommands;
    use RollsBackDatabase;

    public function test_a_revision_is_stored_and_clears_the_listings_cache_once(): void
    {
        $purger = $this->purger();
        $purges = $purger->listings;
        $data = $this->data(KnownTiers::PRO);
        $data->name = 'Pro Plus';
        $data->priceRappen = 24950;
        $data->rankWeight = 20;
        $data->photosMax = 80;
        $data->contactVisible = false;
        $data->eventTypesMax = 8;

        $this->dispatch($data->toCommand(KnownTiers::PRO, ['name', 'priceRappen', 'rankWeight', 'photosMax', 'contactVisible', 'eventTypesMax']));

        self::assertEquals(
            ['name' => 'Pro Plus', 'price_rappen' => 24950, 'rank_weight' => 20, 'photos_max' => 80, 'rooms_max' => 20, 'videos_max' => 5, 'documents_max' => 5, 'contact_visible' => false, 'last_minute_allowed' => true, 'detailed_report' => true, 'event_types_max' => 8],
            $this->connection->fetchAssociative("SELECT name, price_rappen, rank_weight, photos_max, rooms_max, videos_max, documents_max, contact_visible, last_minute_allowed, detailed_report, event_types_max FROM tiers WHERE code = 'pro'"),
        );
        self::assertSame($purges + 1, $purger->listings);
        self::assertSame([], $purger->venues);
        self::assertSame('Free', $this->connection->fetchOne("SELECT name FROM tiers WHERE code = 'free'"), 'Only the tier asked for changes.');
    }

    public function test_the_limits_the_resolver_hands_out_are_the_revised_ones(): void
    {
        $data = $this->data(KnownTiers::FREE);
        $data->photosMax = 9;
        $data->documentsMax = 2;
        $data->eventTypesMax = 4;
        $data->contactVisible = true;

        $this->dispatch($data->toCommand(KnownTiers::FREE, ['photosMax', 'documentsMax', 'eventTypesMax', 'contactVisible']));

        $entitlements = new EntitlementResolver($this->connection)->forTier(KnownTiers::FREE);
        self::assertSame(9, $entitlements->photosMax);
        self::assertSame(2, $entitlements->documentsMax);
        self::assertSame(4, $entitlements->eventTypesMax);
        self::assertTrue($entitlements->contactVisible);
        self::assertSame(1, $entitlements->roomsMax, 'A limit nobody touched stays.');
    }

    public function test_a_revision_leaves_one_audit_row_that_names_the_fields_and_not_the_values(): void
    {
        $data = $this->data(KnownTiers::PRO);
        $data->priceRappen = 24950;
        $data->name = 'Pro Plus';

        $this->dispatch($data->toCommand(KnownTiers::PRO, ['name', 'priceRappen']));

        $rows = $this->connection->fetchAllAssociative("SELECT command, subject_id, changes::text AS changes FROM admin_audit_log WHERE subject_type = 'tier'");
        self::assertCount(1, $rows);
        self::assertSame(ReviseTier::class, $rows[0]['command']);
        self::assertSame('pro', $rows[0]['subject_id']);
        self::assertSame(['changed' => ['name', 'priceRappen']], json_decode((string) $rows[0]['changes'], true, flags: \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('24950', (string) $rows[0]['changes']);
        self::assertStringNotContainsString('Pro Plus', (string) $rows[0]['changes']);
    }

    public function test_values_that_are_already_stored_change_nothing_and_clear_nothing(): void
    {
        $purger = $this->purger();
        $purges = $purger->listings;
        $before = $this->connection->fetchOne("SELECT updated_at FROM tiers WHERE code = 'pro'");

        $this->dispatch($this->data(KnownTiers::PRO)->toCommand(KnownTiers::PRO, []));

        self::assertSame($before, $this->connection->fetchOne("SELECT updated_at FROM tiers WHERE code = 'pro'"));
        self::assertSame($purges, $purger->listings);
    }

    public function test_an_unknown_code_is_a_domain_error_and_leaves_no_trace(): void
    {
        $purger = $this->purger();
        $purges = $purger->listings;
        $command = $this->data(KnownTiers::PRO)->toCommand('gold', ['name']);

        try {
            $this->dispatch($command);
            self::fail('The command should have failed.');
        } catch (HandlerFailedException $failure) {
            self::assertNotSame([], $failure->getWrappedExceptions(TierNotFound::class));
        }

        self::assertSame($purges, $purger->listings);
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'tier'"));
    }

    public function test_a_name_another_tier_has_is_a_domain_error_and_leaves_no_trace(): void
    {
        $purger = $this->purger();
        $purges = $purger->listings;
        $data = $this->data(KnownTiers::PRO);
        $data->name = 'Free';
        $data->photosMax = 99;

        try {
            $this->dispatch($data->toCommand(KnownTiers::PRO, ['name', 'photosMax']));
            self::fail('The command should have failed.');
        } catch (HandlerFailedException $failure) {
            self::assertNotSame([], $failure->getWrappedExceptions(TierNameTaken::class));
        }

        self::assertSame([['free', 'Free', 6], ['pro', 'Pro', 60]], $this->namesAndPhotos());
        self::assertSame($purges, $purger->listings);
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'tier'"));
    }

    public function test_the_vat_line_alone_is_a_change_that_clears_the_cache(): void
    {
        $purger = $this->purger();
        $purges = $purger->listings;
        $data = $this->data(KnownTiers::PRO);
        self::assertFalse($data->vatLine, 'D24: the seeded price is final, without a VAT line');
        $data->vatLine = true;

        $this->dispatch($data->toCommand(KnownTiers::PRO, ['vatLine']));

        self::assertTrue($this->connection->fetchOne("SELECT vat_line FROM tiers WHERE code = 'pro'"));
        self::assertFalse($this->connection->fetchOne("SELECT vat_line FROM tiers WHERE code = 'free'"));
        self::assertSame($purges + 1, $purger->listings);
    }

    public function test_a_tier_keeps_its_own_name_while_other_values_change(): void
    {
        $data = $this->data(KnownTiers::PRO);
        $data->photosMax = 99;

        $this->dispatch($data->toCommand(KnownTiers::PRO, ['photosMax']));

        self::assertSame([['free', 'Free', 6], ['pro', 'Pro', 99]], $this->namesAndPhotos());
    }

    public function test_a_name_taken_between_the_check_and_the_flush_is_the_same_domain_error(): void
    {
        $purger = $this->purger();
        $purges = $purger->listings;
        // A second session renames Free to the name Pro is about to take, after the handler looked and before the key.
        $this->em->getEventManager()->addEventListener([Events::onFlush], new class($this->connection) {
            private bool $fired = false;

            public function __construct(private readonly Connection $connection)
            {
            }

            public function onFlush(OnFlushEventArgs $args): void
            {
                if (!$this->fired) {
                    $this->fired = true;
                    $this->connection->executeStatement("UPDATE tiers SET name = 'Race' WHERE code = 'free'");
                }
            }
        });
        $data = $this->data(KnownTiers::PRO);
        $data->name = 'Race';

        try {
            $this->dispatch($data->toCommand(KnownTiers::PRO, ['name']));
            self::fail('The command should have failed.');
        } catch (HandlerFailedException $failure) {
            self::assertNotSame([], $failure->getWrappedExceptions(TierNameTaken::class));
        }

        self::assertSame([['free', 'Free', 6], ['pro', 'Pro', 60]], $this->namesAndPhotos(), 'The failed save rolled back with the other session\'s rename.');
        self::assertSame($purges, $purger->listings);
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'tier'"));
    }

    /**
     * @return list<array{string, string, int}> code, name and photo limit of every tier, by code
     */
    private function namesAndPhotos(): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row['code'], (string) $row['name'], (int) $row['photos_max']],
            $this->connection->fetchAllAssociative('SELECT code, name, photos_max FROM tiers ORDER BY code'),
        );
    }

    private function data(string $code): TierData
    {
        $definition = new TierDefinitionsQuery($this->connection)->byCode($code);
        self::assertNotNull($definition, $code);

        return TierData::from($definition);
    }
}
