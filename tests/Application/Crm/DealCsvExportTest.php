<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\CsvStream;
use App\Crm\ValueObject\LostReason;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\AdminSession;
use App\Venues\ValueObject\Tier;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * The deal CSV (S13): admins only, the list's filters and order, plain numbers, and no cell that could run.
 */
final class DealCsvExportTest extends WebTestCase
{
    use AdminSession;
    use ClockSensitiveTrait;
    use Factories;

    public function test_an_admin_downloads_the_deals(): void
    {
        self::mockTime('2026-12-31 23:30:00 UTC');
        ['user' => $owner] = $this->logInAs(AdminRole::Admin, 'Andrea Keller');
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        DealFactory::new()->inStage('lost', reason: LostReason::Budget)->create([
            'name' => 'Premium 2027',
            'company' => CompanyFactory::new()->withVenues($venue)->create(['name' => 'Seeblick Events']),
            'venue' => $venue,
            'tier' => Tier::Premium,
            'amountRappen' => 123450,
            'contractStartOn' => new \DateTimeImmutable('2027-01-01'),
            'contractEndOn' => new \DateTimeImmutable('2027-12-31'),
            'expectedCloseOn' => new \DateTimeImmutable('2026-11-15'),
            'owner' => $owner,
        ]);

        $this->client->request('GET', '/admin/crm/deals/export');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=deals-2027-01-01.csv');
        self::assertSame([
            ['Name', 'Location', 'Eintrag', 'Phase', 'Verlustgrund', 'Tier', 'Wert (CHF)', 'Vertragsbeginn', 'Vertragsende', 'Erwarteter Abschluss', 'Zuständig'],
            ['Premium 2027', 'Seeblick Events', 'Schloss Seeblick', 'Verloren', 'Budget', 'Premium', '1234.50', '01.01.2027', '31.12.2027', '15.11.2026', 'Andrea Keller'],
        ], $this->records());
    }

    public function test_an_editor_may_not_export_and_sees_no_button(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/crm/deals/export');
        self::assertResponseStatusCodeSame(403);

        $crawler = $this->client->request('GET', '/admin/crm/deals?ansicht=liste');
        self::assertCount(0, $crawler->filter('a[href*="/export"]'));
    }

    public function test_the_export_keeps_the_filters_and_the_order_and_ignores_the_page(): void
    {
        $this->logInAs();
        DealFactory::new()->inStage('offer')->create(['name' => 'Alpha']);
        DealFactory::new()->inStage('offer')->create(['name' => 'Zeta']);
        DealFactory::createOne(['name' => 'Neu']);

        $this->client->request('GET', '/admin/crm/deals/export?stage=offer&sort=name&dir=desc&page=9');

        self::assertSame(['Zeta', 'Alpha'], array_column(\array_slice($this->records(), 1), 0));
    }

    public function test_the_list_offers_the_export_with_its_filters(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin/crm/deals?ansicht=liste&stage=offer&page=2&sort=name&dir=desc');

        self::assertSame('/admin/crm/deals/export?stage=offer&sort=name&dir=desc', $crawler->filter('.page-header__actions a[href*="/export"]')->attr('href'));
    }

    public function test_a_formula_in_a_cell_never_runs(): void
    {
        $this->logInAs();
        DealFactory::createOne(['name' => '=cmd|\' /C calc\'!A0']);

        $this->client->request('GET', '/admin/crm/deals/export');

        self::assertSame('\'=cmd|\' /C calc\'!A0', $this->records()[1][0]);
    }

    public function test_an_invalid_filter_goes_back_to_the_list_with_its_query(): void
    {
        $this->logInAs();

        $this->client->request('GET', '/admin/crm/deals/export?stage=bogus');

        self::assertResponseRedirects('/admin/crm/deals?ansicht=liste&stage=bogus', 303);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return list<list<string>>
     */
    private function records(): array
    {
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith(CsvStream::BOM, $content);

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
