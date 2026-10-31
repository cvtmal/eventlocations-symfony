<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\ChangeCompanyProperty;
use App\Crm\Command\ChangeContactProperty;
use App\Crm\Entity\Company;
use App\Crm\Entity\Contact;
use App\Crm\Exception\ContactEmailTaken;
use App\Crm\Exception\ContactNotFound;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Exception\StaleProperty;
use App\Crm\ValueObject\CompanyProperty;
use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\ContactKind;
use App\Crm\ValueObject\ContactProperty;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The inline edits of D10 and D11 through command.bus: every field of both enums, the stale check
 * on the loaded value, the no-op, the e-mail clash, and the handler's own guards.
 */
final class PropertyCommandsTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    /**
     * Raw value sent, and the canonical value expected back (`{owner}` and `{company}` are replaced
     * by a fresh id).
     *
     * @return iterable<string, array{CompanyProperty, string, ?string}>
     */
    public static function companyEdits(): iterable
    {
        yield 'name' => [CompanyProperty::Name, '  Neuer Name ', 'Neuer Name'];
        yield 'legal name' => [CompanyProperty::LegalName, 'Neuer Name AG', 'Neuer Name AG'];
        yield 'website' => [CompanyProperty::Website, ' https://neu.example ', 'https://neu.example'];
        yield 'phone' => [CompanyProperty::Phone, '+41 44 000 00 00', '+41 44 000 00 00'];
        yield 'email' => [CompanyProperty::Email, ' Info@Neu.Example ', 'info@neu.example'];
        yield 'address' => [CompanyProperty::Address, 'Seestrasse 1, 8706 Meilen', 'Seestrasse 1, 8706 Meilen'];
        yield 'stage' => [CompanyProperty::Stage, 'customer', 'customer'];
        yield 'owner' => [CompanyProperty::Owner, '{owner}', '{owner}'];
        yield 'notes with CRLF' => [CompanyProperty::Notes, "Erste Zeile\r\nZweite Zeile\n", "Erste Zeile\nZweite Zeile"];
    }

    #[DataProvider('companyEdits')]
    public function test_each_company_field_saves_its_canonical_value(CompanyProperty $property, string $value, ?string $expected): void
    {
        $owner = AdminUserFactory::createOne();
        $company = CompanyFactory::createOne(['name' => 'Alter Name']);
        $value = str_replace('{owner}', $owner->id(), $value);
        $expected = null === $expected ? null : str_replace('{owner}', $owner->id(), $expected);
        $loaded = CompanyProperty::Name === $property ? 'Alter Name' : (CompanyProperty::Stage === $property ? 'prospect' : null);

        $result = $this->dispatch(new ChangeCompanyProperty($company->id(), $property, $value, $loaded, $owner->id()));

        self::assertSame($expected, $result);
        self::assertSame($expected, $this->companyValue($company->id(), $property));
        self::assertSame([['command' => ChangeCompanyProperty::class, 'changes' => ['field' => $property->value]]], $this->auditRows('company', $company->id()));
    }

    /**
     * @return iterable<string, array{ContactProperty, string, ?string}>
     */
    public static function contactEdits(): iterable
    {
        yield 'first name' => [ContactProperty::FirstName, ' Berta ', 'Berta'];
        yield 'last name' => [ContactProperty::LastName, 'Beispiel', 'Beispiel'];
        yield 'email' => [ContactProperty::Email, 'Berta@Example.TEST', 'berta@example.test'];
        yield 'phone' => [ContactProperty::Phone, '+41 79 000 00 00', '+41 79 000 00 00'];
        yield 'position' => [ContactProperty::Position, 'Inhaberin', 'Inhaberin'];
        yield 'kind' => [ContactProperty::Kind, 'couple', 'couple'];
        yield 'company' => [ContactProperty::Company, '{company}', '{company}'];
        yield 'owner' => [ContactProperty::Owner, '{owner}', '{owner}'];
    }

    #[DataProvider('contactEdits')]
    public function test_each_contact_field_saves_its_canonical_value(ContactProperty $property, string $value, ?string $expected): void
    {
        $owner = AdminUserFactory::createOne();
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['firstName' => 'Anna', 'lastName' => 'Muster', 'email' => 'anna@example.test', 'kind' => ContactKind::Other]);
        $ids = ['{owner}' => $owner->id(), '{company}' => $company->id()];
        $value = strtr($value, $ids);
        $expected = null === $expected ? null : strtr($expected, $ids);
        $loaded = match ($property) {
            ContactProperty::FirstName => 'Anna',
            ContactProperty::LastName => 'Muster',
            ContactProperty::Email => 'anna@example.test',
            ContactProperty::Kind => 'other',
            default => null,
        };

        $result = $this->dispatch(new ChangeContactProperty($contact->id(), $property, $value, $loaded, $owner->id()));

        self::assertSame($expected, $result);
        self::assertSame($expected, $this->contactValue($contact->id(), $property));
        self::assertSame([['command' => ChangeContactProperty::class, 'changes' => ['field' => $property->value]]], $this->auditRows('contact', $contact->id()));
    }

    public function test_a_stale_expected_value_is_refused_and_changes_nothing(): void
    {
        $company = CompanyFactory::createOne(['name' => 'Jetzt']);

        $stale = $this->failure(new ChangeCompanyProperty($company->id(), CompanyProperty::Name, 'Mein Name', 'Damals', EntityId::generate()), StaleProperty::class);

        self::assertSame('name', $stale->field);
        self::assertSame('Jetzt', $this->companyValue($company->id(), CompanyProperty::Name));
        self::assertSame([], $this->auditRows('company', $company->id()));
    }

    public function test_a_stale_expected_contact_value_is_refused_and_changes_nothing(): void
    {
        $contact = ContactFactory::createOne(['lastName' => 'Jetzt']);

        $stale = $this->failure(new ChangeContactProperty($contact->id(), ContactProperty::LastName, 'Neu', 'Damals', EntityId::generate()), StaleProperty::class);

        self::assertSame('last_name', $stale->field);
        self::assertSame('Jetzt', $this->contactValue($contact->id(), ContactProperty::LastName));
        self::assertSame([], $this->auditRows('contact', $contact->id()));
    }

    public function test_the_expected_value_is_compared_canonically(): void
    {
        $contact = ContactFactory::createOne(['email' => 'anna@example.test']);

        $result = $this->dispatch(new ChangeContactProperty($contact->id(), ContactProperty::Email, 'neu@example.test', ' ANNA@example.test ', EntityId::generate()));

        self::assertSame('neu@example.test', $result);
    }

    public function test_an_unchanged_value_is_a_no_op_even_when_stale(): void
    {
        $company = CompanyFactory::createOne(['name' => 'Gleich']);
        $this->connection->executeStatement("UPDATE crm_companies SET updated_at = updated_at - INTERVAL '1 hour' WHERE id = ?", [$company->id()]);
        $this->em->clear();
        $before = $this->updatedAt($company->id());

        $result = $this->dispatch(new ChangeCompanyProperty($company->id(), CompanyProperty::Name, ' Gleich ', 'Etwas anderes', EntityId::generate()));

        self::assertSame('Gleich', $result);
        self::assertSame($before, $this->updatedAt($company->id()));
    }

    public function test_an_unchanged_contact_value_is_a_no_op_even_when_stale(): void
    {
        $contact = ContactFactory::createOne(['lastName' => 'Gleich']);
        $this->connection->executeStatement("UPDATE crm_contacts SET updated_at = updated_at - INTERVAL '1 hour' WHERE id = ?", [$contact->id()]);
        $this->em->clear();
        $before = $this->connection->fetchOne('SELECT updated_at FROM crm_contacts WHERE id = ?', [$contact->id()]);

        $result = $this->dispatch(new ChangeContactProperty($contact->id(), ContactProperty::LastName, ' Gleich ', 'Etwas anderes', EntityId::generate()));

        self::assertSame('Gleich', $result);
        self::assertSame($before, $this->connection->fetchOne('SELECT updated_at FROM crm_contacts WHERE id = ?', [$contact->id()]));
    }

    public function test_emptying_a_field_stores_null(): void
    {
        $company = CompanyFactory::new()->withDetails()->create();
        $website = $company->website();

        $result = $this->dispatch(new ChangeCompanyProperty($company->id(), CompanyProperty::Website, '  ', $website, EntityId::generate()));

        self::assertNull($result);
        self::assertNull($this->companyValue($company->id(), CompanyProperty::Website));
    }

    public function test_a_required_field_cannot_be_emptied(): void
    {
        $company = CompanyFactory::createOne(['name' => 'Bleibt']);
        $contact = ContactFactory::createOne(['lastName' => 'Bleibt']);

        $this->failure(new ChangeCompanyProperty($company->id(), CompanyProperty::Name, '', 'Bleibt', EntityId::generate()), InvalidPropertyValue::class);
        $this->failure(new ChangeContactProperty($contact->id(), ContactProperty::LastName, null, 'Bleibt', EntityId::generate()), InvalidPropertyValue::class);
    }

    public function test_unknown_choices_are_refused(): void
    {
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['kind' => ContactKind::Other]);

        $this->failure(new ChangeCompanyProperty($company->id(), CompanyProperty::Stage, 'lead', 'prospect', EntityId::generate()), InvalidPropertyValue::class);
        $this->failure(new ChangeCompanyProperty($company->id(), CompanyProperty::Owner, EntityId::generate(), null, EntityId::generate()), InvalidPropertyValue::class);
        $this->failure(new ChangeContactProperty($contact->id(), ContactProperty::Kind, 'lead', 'other', EntityId::generate()), InvalidPropertyValue::class);
        $this->failure(new ChangeContactProperty($contact->id(), ContactProperty::Company, 'not-a-uuid', null, EntityId::generate()), InvalidPropertyValue::class);
    }

    public function test_an_email_of_another_contact_is_refused(): void
    {
        ContactFactory::createOne(['email' => 'besetzt@example.test']);
        $contact = ContactFactory::createOne(['email' => null]);

        $this->failure(new ChangeContactProperty($contact->id(), ContactProperty::Email, 'Besetzt@Example.test', null, EntityId::generate()), ContactEmailTaken::class);
    }

    public function test_the_stage_stays_writable_until_deals_exist(): void
    {
        $company = CompanyFactory::new()->inStage(CompanyStage::Customer)->create();

        self::assertSame('former_customer', $this->dispatch(new ChangeCompanyProperty($company->id(), CompanyProperty::Stage, 'former_customer', 'customer', EntityId::generate())));
    }

    public function test_an_unknown_contact_is_not_found(): void
    {
        $this->failure(new ChangeContactProperty(EntityId::generate(), ContactProperty::Phone, '1', null, EntityId::generate()), ContactNotFound::class);
        $this->failure(new ChangeContactProperty('nope', ContactProperty::Phone, '1', null, EntityId::generate()), ContactNotFound::class);
    }

    private function companyValue(string $id, CompanyProperty $property): ?string
    {
        $this->em->clear();
        $company = $this->em->find(Company::class, $id);
        self::assertInstanceOf(Company::class, $company);

        return match ($property) {
            CompanyProperty::Name => $company->name(),
            CompanyProperty::LegalName => $company->legalName(),
            CompanyProperty::Website => $company->website(),
            CompanyProperty::Phone => $company->phone(),
            CompanyProperty::Email => $company->email(),
            CompanyProperty::Address => $company->address(),
            CompanyProperty::Stage => $company->stage()->value,
            CompanyProperty::Owner => $company->owner()?->id(),
            CompanyProperty::Notes => $company->notes(),
        };
    }

    private function contactValue(string $id, ContactProperty $property): ?string
    {
        $this->em->clear();
        $contact = $this->em->find(Contact::class, $id);
        self::assertInstanceOf(Contact::class, $contact);

        return match ($property) {
            ContactProperty::FirstName => $contact->firstName(),
            ContactProperty::LastName => $contact->lastName(),
            ContactProperty::Email => $contact->email(),
            ContactProperty::Phone => $contact->phone(),
            ContactProperty::Position => $contact->position(),
            ContactProperty::Kind => $contact->kind()->value,
            ContactProperty::Company => $contact->company()?->id(),
            ContactProperty::Owner => $contact->owner()?->id(),
        };
    }

    private function updatedAt(string $companyId): string
    {
        return (string) $this->connection->fetchOne('SELECT updated_at FROM crm_companies WHERE id = ?', [$companyId]);
    }
}
