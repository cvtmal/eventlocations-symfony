<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Command\PublishVenue;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * F14, D54 (D32(2), D45): an entry nobody answers for (no active `/konto` manager) goes online only when staff checked
 * its facts and its description is written; an entry with a manager keeps G12's rule (online, noindex until both).
 */
final class DraftRuleTest extends KernelTestCase
{
    use DispatchesVenueCommands;
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase;

    public function test_an_unmanaged_entry_with_unchecked_facts_stays_a_draft(): void
    {
        $venue = $this->draft(checked: false, description: 'Ein Saal mit Blick auf den See.');

        self::assertSame(['facts'], $this->violation(new PublishVenue($venue->number()))->missing);
        self::assertFalse($this->reload($venue)->isPublished());
    }

    public function test_an_unmanaged_entry_without_a_description_stays_a_draft(): void
    {
        $venue = $this->draft(checked: true, description: null);

        self::assertSame(['description'], $this->violation(new PublishVenue($venue->number()))->missing);
    }

    public function test_every_gap_is_named_in_the_rules_order(): void
    {
        $venue = VenueFactory::new()->withUncheckedFacts()->withoutInquiryEmail()->create(['status' => VenueStatus::Unpublished]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'summary' => ' ', 'description' => "  \n "]);

        self::assertSame(['summary', 'main_building', 'photo', 'inquiry_email', 'facts', 'description'], $this->violation(new PublishVenue($venue->number()))->missing);
    }

    public function test_checked_facts_and_a_description_let_an_unmanaged_entry_go_online(): void
    {
        $venue = $this->draft(checked: true, description: 'Ein Saal mit Blick auf den See.');

        $this->dispatch(new PublishVenue($venue->number()));

        self::assertTrue($this->reload($venue)->isPublished());
    }

    public function test_an_entry_with_an_active_manager_goes_online_unchecked_and_without_a_description(): void
    {
        $venue = $this->draft(checked: false, description: null);
        PortalVenueAccessFactory::new()->active()->create(['venue' => $venue]);

        $this->dispatch(new PublishVenue($venue->number()));

        self::assertTrue($this->reload($venue)->isPublished());
    }

    /**
     * D60: a manager lifts the draft rule, never the inquiry address.
     */
    public function test_an_entry_with_an_active_manager_still_needs_an_inquiry_address(): void
    {
        $venue = $this->draft(checked: false, description: null);
        PortalVenueAccessFactory::new()->active()->create(['venue' => $venue]);
        $this->connection->executeStatement('UPDATE venues SET inquiry_email = NULL WHERE id = ?', [$venue->id()]);

        self::assertSame(['inquiry_email'], $this->violation(new PublishVenue($venue->number()))->missing);
        self::assertFalse($this->reload($venue)->isPublished());
    }

    public function test_a_pending_access_request_is_no_manager(): void
    {
        $venue = $this->draft(checked: false, description: null);
        PortalVenueAccessFactory::createOne(['venue' => $venue]);

        self::assertSame(['facts', 'description'], $this->violation(new PublishVenue($venue->number()))->missing);
    }

    private function draft(bool $checked, ?string $description): Venue
    {
        $factory = VenueFactory::new();
        $venue = ($checked ? $factory : $factory->withUncheckedFacts())->create(['status' => VenueStatus::Unpublished]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'summary' => 'Ein Ort für den Anlass.', 'description' => $description]);
        VenueTermFactory::new()->term(TermGroup::Building, 'castle')->main()->create(['venue' => $venue]);
        VenuePhotoFactory::createOne(['venue' => $venue]);

        return $venue;
    }
}
