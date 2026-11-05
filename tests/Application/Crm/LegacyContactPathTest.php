<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * A contact had a page under `/admin/crm/kontakte/{id}` until #55 made it a row on its Location's card: an old bookmark
 * answers 301 to that card, the list to the Locations list; the CSV under `/admin/crm/kontakte/export` stays.
 */
final class LegacyContactPathTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_a_contact_page_moves_to_its_location_s_card(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company]);

        $this->client->request('GET', '/admin/crm/kontakte/'.$contact->id());

        self::assertResponseRedirects('/admin/crm/locations/'.$company->id().'#kontakte', 301);
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
    }

    public function test_an_unknown_or_removed_contact_is_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/crm/kontakte/'.EntityId::generate());
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/crm/kontakte/not-a-uuid');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/crm/kontakte/neu');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_list_moves_to_the_locations_without_its_old_filters(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/crm/kontakte?kind=couple&owner=none');

        self::assertResponseRedirects('/admin/crm/locations', 301);
    }

    public function test_anonymous_is_sent_to_the_login(): void
    {
        $contact = ContactFactory::createOne();

        $this->client->request('GET', '/admin/crm/kontakte/'.$contact->id());

        self::assertResponseRedirects('/admin/login');
    }

    public function test_the_export_stays_the_csv_for_an_admin_and_refuses_an_editor(): void
    {
        $this->logInAs(AdminRole::Admin);

        $this->client->request('GET', '/admin/crm/kontakte/export');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));

        $this->logInAs(AdminRole::Editor, 'Beat Meier');

        $this->client->request('GET', '/admin/crm/kontakte/export');

        self::assertResponseStatusCodeSame(403);
    }
}
