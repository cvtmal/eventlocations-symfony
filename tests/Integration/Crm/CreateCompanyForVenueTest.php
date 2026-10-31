<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\CreateCompanyForVenue;
use App\Crm\Command\DeleteCompany;
use App\Crm\Command\EraseContact;
use App\Crm\Command\UnlinkVenue;
use App\Crm\Exception\ContactEmailTaken;
use App\Crm\Exception\VenueNotFound;
use App\Crm\ValueObject\BootstrapOutcome;
use App\Crm\ValueObject\ContactKind;
use App\Factory\ContactFactory;
use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * One company per venue (CRM-1, D19): the venue's facts, its contact person as a `venue_contact`, a
 * taken e-mail left out, and a repeat that changes nothing. The venue itself is only read.
 */
final class CreateCompanyForVenueTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase;

    public function test_a_venue_with_a_town_gives_a_company_with_its_facts(): void
    {
        $venue = $this->venue('Schloss Seeblick', 'Laura Meier', 'Info@Seeblick.example.ch');
        $venue->changeContact('+41441234567', 'Info@Seeblick.example.ch', 'https://seeblick.example.ch', 'Laura Meier');
        $venue->relocate($venue->town(), $venue->zip(), 'Seestrasse 1', $venue->lat(), $venue->lng());
        $this->em->flush();
        $town = $venue->town();
        self::assertNotNull($town);
        $id = EntityId::generate();

        $outcome = $this->dispatch(new CreateCompanyForVenue($venue->id(), $id));

        self::assertInstanceOf(BootstrapOutcome::class, $outcome);
        self::assertSame($id, $outcome->companyId);
        self::assertTrue($outcome->contactCreated);
        self::assertFalse($outcome->emailTaken);
        self::assertSame(
            [
                'name' => 'Schloss Seeblick',
                'website' => 'https://seeblick.example.ch',
                'phone' => '+41441234567',
                'email' => 'info@seeblick.example.ch',
                'address' => 'Seestrasse 1, '.$venue->zip().' '.$town->name(),
                'stage' => 'prospect',
                'origin' => 'venue',
                'owner_id' => null,
            ],
            $this->connection->fetchAssociative('SELECT name, website, phone, email, address, stage, origin, owner_id FROM crm_companies WHERE id = ?', [$id]),
        );
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_company_venues WHERE company_id = ? AND venue_id = ?', $id, $venue->id()));
        self::assertSame(
            ['first_name' => 'Laura', 'last_name' => 'Meier', 'email' => 'info@seeblick.example.ch', 'kind' => ContactKind::VenueContact->value, 'company_id' => $id],
            $this->connection->fetchAssociative('SELECT first_name, last_name, email, kind, company_id FROM crm_contacts WHERE company_id = ?', [$id]),
        );
        self::assertSame([['command' => CreateCompanyForVenue::class, 'changes' => ['venue' => $venue->id()]]], $this->auditRows('company', $id));
    }

    public function test_a_venue_without_a_town_has_no_address(): void
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Ohne Ort']);
        $id = EntityId::generate();

        $outcome = $this->dispatch(new CreateCompanyForVenue($venue->id(), $id));

        self::assertInstanceOf(BootstrapOutcome::class, $outcome);
        self::assertFalse($outcome->contactCreated);
        self::assertSame(
            ['name' => 'Ohne Ort', 'website' => null, 'phone' => null, 'email' => null, 'address' => null],
            $this->connection->fetchAssociative('SELECT name, website, phone, email, address FROM crm_companies WHERE id = ?', [$id]),
        );
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_contacts WHERE company_id = ?', $id));
    }

    public function test_a_single_word_contact_person_is_a_last_name(): void
    {
        $venue = $this->venue('Eine Person', '  Meier ');

        $id = EntityId::generate();
        $this->dispatch(new CreateCompanyForVenue($venue->id(), $id));

        self::assertSame(
            ['first_name' => null, 'last_name' => 'Meier'],
            $this->connection->fetchAssociative('SELECT first_name, last_name FROM crm_contacts WHERE company_id = ?', [$id]),
        );
    }

    public function test_the_last_word_is_the_last_name_and_the_rest_the_first_name(): void
    {
        $venue = $this->venue('Drei Worte', "Anna  Maria\tAllmen");

        $id = EntityId::generate();
        $this->dispatch(new CreateCompanyForVenue($venue->id(), $id));

        self::assertSame(
            ['first_name' => 'Anna Maria', 'last_name' => 'Allmen'],
            $this->connection->fetchAssociative('SELECT first_name, last_name FROM crm_contacts WHERE company_id = ?', [$id]),
        );
    }

    public function test_a_blank_contact_person_gives_no_contact(): void
    {
        $venue = $this->venue('Leer', '   ');

        $id = EntityId::generate();
        $outcome = $this->dispatch(new CreateCompanyForVenue($venue->id(), $id));

        self::assertInstanceOf(BootstrapOutcome::class, $outcome);
        self::assertFalse($outcome->contactCreated);
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_contacts WHERE company_id = ?', $id));
    }

    public function test_a_taken_email_is_left_out_of_the_contact_and_kept_on_the_company(): void
    {
        ContactFactory::createOne(['email' => 'info@teilen.example.ch']);
        $venue = $this->venue('Geteilte Adresse', 'Laura Meier', 'INFO@teilen.example.ch');
        $id = EntityId::generate();

        $outcome = $this->dispatch(new CreateCompanyForVenue($venue->id(), $id));

        self::assertInstanceOf(BootstrapOutcome::class, $outcome);
        self::assertTrue($outcome->contactCreated);
        self::assertTrue($outcome->emailTaken);
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts WHERE company_id = ? AND email IS NULL', $id));
        self::assertSame('info@teilen.example.ch', $this->connection->fetchOne('SELECT email FROM crm_companies WHERE id = ?', [$id]));
    }

    public function test_an_address_taken_after_the_check_is_refused_and_writes_nothing(): void
    {
        $venue = $this->venue('Knapp', 'Laura Meier', 'info@knapp.example.ch');
        $id = EntityId::generate();
        $this->em->getEventManager()->addEventListener(Events::preFlush, new class($this->connection) {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function preFlush(): void
            {
                $this->connection->executeStatement("INSERT INTO crm_contacts (id, last_name, kind, email) VALUES (?, 'Keller', 'other', 'info@knapp.example.ch')", [EntityId::generate()]);
            }
        });

        $this->failure(new CreateCompanyForVenue($venue->id(), $id), ContactEmailTaken::class);

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_companies WHERE id = ?', $id));
        self::assertSame([], $this->auditRows('company', $id));
    }

    /**
     * Accepted by the lead for the owner's Outcome (review m5): CRM-1 reads "one company per venue
     * that has none", so nothing records that a venue has none on purpose. An erased person comes
     * back from `venues.contact_person` when the venue is unlinked or its company deleted. A tombstone
     * or a hidden button would change this test.
     *
     * @param 'unlink'|'delete' $how
     */
    #[DataProvider('freedVenues')]
    public function test_a_venue_freed_again_gets_a_new_company_and_its_contact_person_back(string $how): void
    {
        $venue = $this->venue('Wieder da', 'Laura Meier', 'laura@wieder.example.ch');
        $first = EntityId::generate();
        $this->dispatch(new CreateCompanyForVenue($venue->id(), $first));
        $this->dispatch(new EraseContact((string) $this->connection->fetchOne('SELECT id FROM crm_contacts WHERE company_id = ?', [$first])));
        $this->em->clear();
        $this->dispatch('unlink' === $how ? new UnlinkVenue($first, $venue->id()) : new DeleteCompany($first));
        $this->em->clear();

        $second = EntityId::generate();
        $this->dispatch(new CreateCompanyForVenue($venue->id(), $second));

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_company_venues WHERE company_id = ? AND venue_id = ?', $second, $venue->id()));
        self::assertSame(
            ['first_name' => 'Laura', 'last_name' => 'Meier', 'email' => 'laura@wieder.example.ch'],
            $this->connection->fetchAssociative('SELECT first_name, last_name, email FROM crm_contacts WHERE company_id = ?', [$second]),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function freedVenues(): iterable
    {
        yield 'unlinked on purpose' => ['unlink'];
        yield 'its company deleted' => ['delete'];
    }

    public function test_a_repeat_finds_the_link_and_creates_nothing(): void
    {
        $venue = $this->venue('Zweimal', 'Laura Meier');
        $this->dispatch(new CreateCompanyForVenue($venue->id(), EntityId::generate()));
        $this->em->clear();

        $outcome = $this->dispatch(new CreateCompanyForVenue($venue->id(), EntityId::generate()));

        self::assertInstanceOf(BootstrapOutcome::class, $outcome);
        self::assertTrue($outcome->isSkipped());
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_companies'));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts'));
    }

    public function test_the_venue_stays_as_it_was(): void
    {
        $venue = $this->venue('Unberührt', 'Laura Meier');
        $this->connection->executeStatement("UPDATE venues SET updated_at = '2026-01-02 03:04:05+00', cms_edited_at = NULL WHERE id = ?", [$venue->id()]);
        $before = $this->connection->fetchAssociative('SELECT updated_at, cms_edited_at FROM venues WHERE id = ?', [$venue->id()]);
        $this->em->clear();

        $this->dispatch(new CreateCompanyForVenue($venue->id(), EntityId::generate()));

        self::assertSame($before, $this->connection->fetchAssociative('SELECT updated_at, cms_edited_at FROM venues WHERE id = ?', [$venue->id()]));
    }

    public function test_an_unknown_venue_is_refused(): void
    {
        $this->failure(new CreateCompanyForVenue(EntityId::generate(), EntityId::generate()), VenueNotFound::class);
    }

    private function venue(string $name, string $contactPerson, ?string $inquiryEmail = null): Venue
    {
        $venue = VenueFactory::createOne(['name' => $name]);
        $venue->changeContact(null, $inquiryEmail, null, $contactPerson);
        $this->em->flush();

        return $venue;
    }
}
