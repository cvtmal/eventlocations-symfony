<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Controller\BootstrapController;
use App\Factory\CompanyFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The list's bootstrap button (D19): a batch per POST with the same counts as the console, until no
 * venue is left and the button goes.
 */
final class BootstrapButtonTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    private const string FORM = 'form[action="/admin/crm/locations/bootstrap"]';

    public function test_a_post_handles_one_batch_and_a_repeat_finishes(): void
    {
        $this->logInAs(AdminRole::Editor);
        VenueFactory::new()->withoutLocation(null)->many(BootstrapController::BATCH + 1)->create();

        $crawler = $this->client->request('GET', '/admin/crm/locations');
        self::assertSelectorTextContains(self::FORM.' button', 'Locations aus 101 Einträgen anlegen');

        $crawler = $this->press($crawler);

        self::assertSame(100, $this->companies());
        self::assertSelectorTextContains('.toast', '100 Locations und 0 Kontakte angelegt.');
        self::assertSelectorTextContains('.toast', 'Ohne Kontaktperson: 100.');
        self::assertSelectorTextContains('.toast', 'Noch offen: 1.');
        self::assertSelectorTextContains(self::FORM.' button', 'Location aus 1 Eintrag anlegen');

        $this->press($crawler);

        self::assertSame(101, $this->companies());
        self::assertSelectorTextContains('.toast', '1 Location und 0 Kontakte angelegt.');
        self::assertSelectorTextContains('.toast', 'Noch offen: 0.');
        self::assertSelectorNotExists(self::FORM);
    }

    public function test_the_button_is_gone_when_every_venue_has_a_company(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation(null)->create();
        CompanyFactory::new()->withVenues($venue)->create();

        $this->client->request('GET', '/admin/crm/locations');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(self::FORM);
    }

    public function test_a_post_without_its_token_creates_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        VenueFactory::new()->withoutLocation(null)->create();

        $this->client->request('POST', '/admin/crm/locations/bootstrap');

        self::assertResponseRedirects('/admin/crm/locations', 303);
        self::assertSame(0, $this->companies());
    }

    public function test_a_failing_venue_is_named_and_the_batch_goes_on(): void
    {
        $this->logInAs(AdminRole::Editor);
        // A real driver failure: its message quotes the failing row.
        $this->connection->executeStatement("ALTER TABLE crm_companies ADD CONSTRAINT chk_test_bootstrap CHECK (name <> 'Kaputt') NOT VALID");
        $broken = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Kaputt']);
        VenueFactory::new()->withoutLocation(null)->create(['name' => 'Heil']);

        $this->press($this->client->request('GET', '/admin/crm/locations'));

        self::assertSame(1, $this->companies());
        self::assertSelectorTextContains('.toast--success', 'Fehlgeschlagen: 1. Noch offen: 1.');
        self::assertSelectorTextSame('.toast--error .toast__message', 'Nicht angelegt: '.$broken->number().': chk_test_bootstrap');
        self::assertSelectorTextContains(self::FORM.' button', 'Location aus 1 Eintrag anlegen');
    }

    private function press(Crawler $crawler): Crawler
    {
        $this->client->submit($crawler->filter(self::FORM)->form());
        self::assertResponseRedirects('/admin/crm/locations', 303);

        return $this->client->followRedirect();
    }

    private function companies(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM crm_companies');
    }
}
