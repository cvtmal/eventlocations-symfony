<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Entity\ActivityType;
use App\Factory\ActivityFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\TaskFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Deleting a contact from its record (CRM-11, D16): the page's `Aktionen`, the confirmation, PRG to
 * the list. What the erasure does to the data is `EraseContactTest`'s.
 */
final class ContactEraseTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ForgedPosts;

    public function test_an_admin_erases_a_contact_through_the_modal(): void
    {
        $this->logInAs();
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events GmbH']);
        $contact = ContactFactory::createOne(['firstName' => 'Zelda', 'lastName' => 'Unverwechselbar', 'company' => $company]);
        $contactId = $contact->id();
        $shared = ActivityFactory::new()->ofType(ActivityType::CALL)->onCompany($company)->onContact($contact)->create(['body' => 'Anruf mit Zelda.']);
        TaskFactory::createOne(['contact' => $contact, 'title' => 'Zelda anrufen']);
        $this->entityManager()->clear();

        $crawler = $this->client->request('GET', '/admin/crm/kontakte/'.$contactId);

        self::assertResponseIsSuccessful();
        self::assertSame('contact-erase', $crawler->filter('#record-actions button.dropdown__item--danger')->attr('commandfor'));
        self::assertSelectorExists('#contact-erase form[method="dialog"] button[autofocus]');
        self::assertSelectorTextContains('#contact-erase', '«Zelda Unverwechselbar» wird endgültig gelöscht');
        self::assertSelectorTextContains('#contact-erase', 'Steht dieselbe E-Mail-Adresse bei der Location, wird sie dort ebenfalls entfernt.');
        self::assertSelectorTextContains('#contact-erase', 'muss von Hand im CMS entfernt werden');
        $this->client->submit($crawler->filter('#contact-erase form[action$="/loeschen"]')->form());

        self::assertResponseRedirects('/admin/crm/kontakte', 303);
        $crawler = $this->client->followRedirect();
        self::assertSame('Kontakt gelöscht.', trim($crawler->filter('.toast')->text()));
        self::assertStringNotContainsString('Unverwechselbar', (string) $this->client->getResponse()->getContent());

        $connection = $this->entityManager()->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM crm_contacts WHERE id = ?', [$contactId]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM crm_tasks WHERE contact_id = ?', [$contactId]));
        self::assertNull($connection->fetchOne('SELECT body FROM crm_activities WHERE id = ?', [$shared->id()]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM crm_companies WHERE id = ?', [$company->id()]));
        self::assertSame(
            ['erased'],
            array_map(
                static fn (string $changes): mixed => json_decode($changes, true, flags: \JSON_THROW_ON_ERROR)['action'] ?? null,
                $connection->fetchFirstColumn("SELECT changes::text FROM admin_audit_log WHERE subject_type = 'contact' AND subject_id = ?", [$contactId]),
            ),
        );
    }

    public function test_the_export_action_sits_beside_it(): void
    {
        $this->logInAs();
        $contact = ContactFactory::createOne();

        $crawler = $this->client->request('GET', '/admin/crm/kontakte/'.$contact->id());

        self::assertSame('/admin/crm/kontakte/'.$contact->id().'/export', $crawler->filter('#record-actions a.dropdown__item')->attr('href'));
    }

    public function test_an_editor_sees_no_actions_and_may_not_erase(): void
    {
        $this->logInAs(AdminRole::Editor);
        $contact = ContactFactory::createOne();

        $this->client->request('GET', '/admin/crm/kontakte/'.$contact->id());
        self::assertSelectorNotExists('#record-actions');
        self::assertSelectorNotExists('#contact-erase');

        $this->client->request('POST', '/admin/crm/kontakte/'.$contact->id().'/loeschen');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne('SELECT count(*) FROM crm_contacts WHERE id = ?', [$contact->id()]));
    }

    /**
     * @param array<string, string> $token
     * @param array<string, string> $server
     */
    #[DataProvider('forgedPosts')]
    public function test_a_forged_post_erases_nothing(array $token, array $server): void
    {
        $this->logInAs();
        $contact = ContactFactory::createOne();

        $this->client->request('POST', '/admin/crm/kontakte/'.$contact->id().'/loeschen', ['erase_contact' => $token], server: $server);

        self::assertResponseRedirects('/admin/crm/kontakte/'.$contact->id(), 303);
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne('SELECT count(*) FROM crm_contacts WHERE id = ?', [$contact->id()]));
    }

    public function test_an_unknown_contact_is_a_404(): void
    {
        $this->logInAs();
        $contact = ContactFactory::createOne();
        $crawler = $this->client->request('GET', '/admin/crm/kontakte/'.$contact->id());
        $form = $crawler->filter('#contact-erase form[action$="/loeschen"]')->form();
        $form->getNode()->setAttribute('action', '/admin/crm/kontakte/'.EntityId::generate().'/loeschen');

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(404);
    }
}
