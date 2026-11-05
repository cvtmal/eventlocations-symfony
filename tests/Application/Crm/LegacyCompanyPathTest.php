<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Factory\CompanyFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The CRM pages moved from `/admin/crm/firmen…` to `/admin/crm/locations…` (#54): an old GET answers 301
 * to the new page with its query, the export stays admin-only, and the old POST paths are gone.
 */
final class LegacyCompanyPathTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    private const string QUERY = '?stage=customer&sort=created&dir=desc';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function oldPages(): iterable
    {
        yield 'list' => ['/admin/crm/firmen', '/admin/crm/locations'];
        yield 'new' => ['/admin/crm/firmen/neu', '/admin/crm/locations/neu'];
        yield 'record' => ['/admin/crm/firmen/{id}', '/admin/crm/locations/{id}'];
        yield 'deals' => ['/admin/crm/firmen/{id}/deals', '/admin/crm/locations/{id}/deals'];
    }

    #[DataProvider('oldPages')]
    public function test_each_old_page_moves_permanently_with_its_query(string $old, string $new): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = CompanyFactory::createOne()->id();

        $this->client->request('GET', str_replace('{id}', $id, $old).self::QUERY);

        self::assertResponseRedirects(str_replace('{id}', $id, $new).self::QUERY, 301);
    }

    public function test_the_bare_list_moves_without_a_query(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/crm/firmen');

        self::assertResponseRedirects('/admin/crm/locations', 301);
    }

    public function test_the_old_export_moves_for_an_admin_and_refuses_an_editor(): void
    {
        $this->logInAs(AdminRole::Admin);

        $this->client->request('GET', '/admin/crm/firmen/export'.self::QUERY);

        self::assertResponseRedirects('/admin/crm/locations/export'.self::QUERY, 301);

        $this->logInAs(AdminRole::Editor, 'Beat Meier');

        $this->client->request('GET', '/admin/crm/firmen/export');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_old_post_paths_are_gone(): void
    {
        $this->logInAs(AdminRole::Admin);
        $company = CompanyFactory::createOne();
        $venue = VenueFactory::new()->withoutLocation(null)->create();

        $this->client->request('POST', '/admin/crm/firmen/bootstrap');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/admin/crm/firmen/'.$company->id().'/loeschen');
        self::assertResponseStatusCodeSame(404);

        // The GET legacy route matches this path, so the router answers with the allowed method.
        $this->client->request('POST', '/admin/crm/firmen/neu');
        self::assertResponseStatusCodeSame(405);

        $this->client->request('POST', '/admin/crm/locations/'.$venue->id().'/firma');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_an_anonymous_visitor_goes_to_the_login_first(): void
    {
        $id = CompanyFactory::createOne()->id();

        $this->client->request('GET', '/admin/crm/firmen/'.$id);

        self::assertResponseRedirects('/admin/login');
    }
}
