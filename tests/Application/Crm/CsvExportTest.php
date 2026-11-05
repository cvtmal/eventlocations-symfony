<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\CsvStream;
use App\Crm\Entity\Company;
use App\Crm\Entity\Contact;
use App\Crm\ValueObject\CompanyStage;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The CSV exports (CRM-6, D20): admins only, the list's filters and order, Swiss Excel's format and
 * no cell that could run as a formula.
 */
final class CsvExportTest extends WebTestCase
{
    use AdminSession;
    use ClockSensitiveTrait;
    use Factories;

    public function test_an_admin_downloads_the_companies(): void
    {
        self::mockTime('2026-12-31 23:30:00 UTC');
        ['user' => $owner] = $this->logInAs(AdminRole::Admin, 'Andrea Keller');
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        $company = CompanyFactory::new()->inStage(CompanyStage::Customer)->withVenue($venue)
            ->afterInstantiate(static function (Company $company): void {
                $company->changeLegalName('Seeblick Events GmbH, Zürich');
                $company->changeWebsite('https://seeblick.example.ch');
                $company->changeEmail('info@seeblick.example.ch');
                $company->changeAddress("Seestrasse 1\n8706 Meilen");
            })
            ->create(['name' => 'Seeblick Events', 'owner' => $owner]);
        $this->entityManager()->getConnection()->executeStatement("UPDATE crm_companies SET created_at = '2026-12-31 23:30:00+00' WHERE id = ?", [$company->id()]);

        $this->client->request('GET', '/admin/crm/locations/export');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        // After 23:00 UTC it is the next day in Zürich: the file name and the cell carry the staff's date.
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=locations-2027-01-01.csv');
        $content = $this->content();
        self::assertStringStartsWith(CsvStream::BOM, $content);
        self::assertSame([
            ['Name', 'Firmenname', 'Website', 'Telefon', 'E-Mail', 'Adresse', 'Phase', 'Zuständig', 'Eintrag', 'Herkunft', 'Erstellt'],
            ['Seeblick Events', 'Seeblick Events GmbH, Zürich', 'https://seeblick.example.ch', '', 'info@seeblick.example.ch', "Seestrasse 1\n8706 Meilen", 'Kunde', 'Andrea Keller', 'Schloss Seeblick', 'Manuell erfasst', '01.01.2027'],
        ], $this->records($content));
    }

    public function test_an_admin_downloads_the_contacts(): void
    {
        self::mockTime('2026-06-15 10:00:00 UTC');
        ['user' => $owner] = $this->logInAs();
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events']);
        ContactFactory::new()
            ->afterInstantiate(static function (Contact $contact): void {
                $contact->changePosition('Eventmanagerin');
                $contact->changePhone('+41791234567');
            })
            ->create(['firstName' => 'Laura', 'lastName' => 'Meier', 'company' => $company, 'email' => 'laura@example.ch']);

        $this->client->request('GET', '/admin/crm/kontakte/export');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=kontakte-2026-06-15.csv');
        // P13: an E.164 number leads with `+`, so it takes the `'` like any other cell.
        self::assertSame([
            ['Vorname', 'Nachname', 'Location', 'Position', 'E-Mail', 'Telefon'],
            ['Laura', 'Meier', 'Seeblick Events', 'Eventmanagerin', 'laura@example.ch', "'+41791234567"],
        ], $this->records($this->content()));
    }

    public function test_an_editor_may_not_export(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/crm/locations/export');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/crm/kontakte/export');
        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_export_keeps_the_filters_and_the_order_and_ignores_the_page(): void
    {
        $this->logInAs();
        CompanyFactory::new()->inStage(CompanyStage::Customer)->create(['name' => 'Alpenhof AG']);
        CompanyFactory::new()->inStage(CompanyStage::Customer)->create(['name' => 'Zürcher Gastro AG']);
        CompanyFactory::createOne(['name' => 'Interessent AG']);
        $this->client->request('GET', '/admin/crm/locations/export?stage=customer&sort=name&dir=desc&page=9');
        self::assertSame(['Zürcher Gastro AG', 'Alpenhof AG'], array_column(\array_slice($this->records($this->content()), 1), 0));

        $this->client->request('GET', '/admin/crm/locations/export?q=keinetreffer');
        self::assertCount(1, $this->records($this->content()));
    }

    public function test_the_export_holds_more_than_one_page_of_the_list(): void
    {
        $this->logInAs();
        CompanyFactory::createMany(51);

        $this->client->request('GET', '/admin/crm/locations/export');

        self::assertCount(52, $this->records($this->content()));
    }

    public function test_a_formula_in_a_cell_never_runs(): void
    {
        $this->logInAs();
        CompanyFactory::createOne(['name' => '=cmd|\' /C calc\'!A0']);
        ContactFactory::createOne(['firstName' => '@SUM(1)', 'lastName' => '-2+3']);

        $this->client->request('GET', '/admin/crm/locations/export');
        self::assertSame('\'=cmd|\' /C calc\'!A0', $this->records($this->content())[1][0]);

        $this->client->request('GET', '/admin/crm/kontakte/export');
        self::assertSame(["'@SUM(1)", "'-2+3"], \array_slice($this->records($this->content())[1], 0, 2));
    }

    public function test_an_invalid_filter_goes_back_to_the_list_with_its_query(): void
    {
        $this->logInAs();

        $this->client->request('GET', '/admin/crm/locations/export?stage=bogus');
        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/admin/crm/locations?stage=bogus', 303);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(422);
    }

    public function test_the_lists_offer_the_export_to_admins_with_the_current_filters(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin/crm/locations?stage=customer&page=2&sort=created&dir=desc');
        // The contacts have no list of their own since #55: their CSV, unfiltered, sits on the Locations list.
        self::assertSame(
            ['/admin/crm/locations/export?stage=customer&sort=created&dir=desc', '/admin/crm/kontakte/export'],
            $crawler->filter('.page-header__actions a[href*="/export"]')->each(static fn (Crawler $link): string => (string) $link->attr('href')),
        );
        self::assertSame('Kontakte exportieren', $crawler->filter('.page-header__actions a[href="/admin/crm/kontakte/export"]')->text());
    }

    public function test_an_editor_sees_no_export_button(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', '/admin/crm/locations');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href*="/export"]'));
    }

    private function content(): string
    {
        self::assertResponseIsSuccessful();

        return (string) $this->client->getInternalResponse()->getContent();
    }

    /**
     * Parses the body as a spreadsheet does, through a real stream, so a quoted line break stays in
     * its cell.
     *
     * @return list<list<string>>
     */
    private function records(string $content): array
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, substr($content, \strlen(CsvStream::BOM)));
        rewind($stream);

        $records = [];
        while (false !== ($record = fgetcsv($stream, null, CsvStream::SEPARATOR, CsvStream::ENCLOSURE, CsvStream::ESCAPE))) {
            $records[] = array_map(static fn (?string $cell): string => (string) $cell, $record);
        }
        fclose($stream);

        return $records;
    }
}
