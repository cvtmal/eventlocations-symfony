<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Entity\Company;
use App\Crm\ReadModel\CustomerMatchCompany;
use App\Crm\ReadModel\CustomerMatchQuery;
use App\Crm\ReadModel\CustomerMatchVenue;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\VenueFactory;
use App\Geo\Entity\Canton;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Venue;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * The customer import's reads (#48 Step 2.1): keys folded by `geo_fold_de()` in one statement, the venues
 * with their company, the companies with their venues' cantons, the contacts' emails, and similar names.
 */
final class CustomerMatchQueryTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_case_umlauts_and_spaces_fold_to_one_key_in_one_statement(): void
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        $holder->reset();

        $keys = $this->query()->fold(['Bären', 'Baeren', 'BÄREN ', 'Bären']);

        self::assertCount(1, $holder->getData()['default'] ?? []);
        self::assertSame(['Bären' => 'baeren', 'Baeren' => 'baeren', 'BÄREN ' => 'baeren'], $keys);
    }

    public function test_sharp_s_folds_like_double_s(): void
    {
        // Research §5.2 asked to record this; the sheet has no ß, so nothing depends on it.
        $keys = $this->query()->fold(['Straße', 'Strasse']);

        self::assertSame('strasse', $keys['Strasse']);
        self::assertSame($keys['Strasse'], $keys['Straße']);
    }

    public function test_a_suffixed_name_folds_to_its_parts_keys_joined_by_one_space(): void
    {
        // CustomerClassifier builds the key of "Name (Suffix)" from the keys of its parts.
        $pairs = [
            ['Gasthof Sternen', 'Zürich'],
            ['Landgasthof Bären, Beispielwil', 'landgasthof-baeren.example.ch'],
            ['Café «Rössli» –', 'St. Gallen'],
            ['Hôtel du Lac!', 'Genève'],
        ];
        $texts = [];
        foreach ($pairs as [$name, $suffix]) {
            array_push($texts, $name, $suffix, \sprintf('%s (%s)', $name, $suffix));
        }

        $keys = $this->query()->fold($texts);

        foreach ($pairs as [$name, $suffix]) {
            self::assertSame($keys[\sprintf('%s (%s)', $name, $suffix)], trim($keys[$name].' '.$keys[$suffix]));
        }
    }

    public function test_quotes_and_backslashes_survive_the_array_parameter(): void
    {
        $keys = $this->query()->fold(['Zum "Hirschen"', 'Back\\slash', '{Klammer}, Ort']);

        self::assertSame(['Zum "Hirschen"' => 'zum hirschen', 'Back\\slash' => 'back slash', '{Klammer}, Ort' => 'klammer ort'], $keys);
    }

    public function test_nothing_to_fold_runs_no_statement(): void
    {
        self::assertSame([], $this->query()->fold([]));
        self::assertSame([], $this->query()->similar([]));
    }

    public function test_venues_come_with_key_canton_website_and_company(): void
    {
        $linked = VenueFactory::new()->withoutLocation($this->canton('be'))
            ->afterInstantiate(static fn (Venue $venue) => $venue->changeContact(null, null, 'https://www.seehaus-erfunden.example.ch/', null))
            ->create(['name' => 'Seehaus Erfunden']);
        $company = CompanyFactory::new()->withVenue($linked)->create(['name' => 'Seehaus Erfunden AG']);
        $alone = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Waldhaus Bärenegg']);

        $venues = $this->byId($this->query()->venues());

        self::assertEquals(new CustomerMatchVenue($linked->id(), 'Seehaus Erfunden', 'seehaus erfunden', 'be', 'https://www.seehaus-erfunden.example.ch/', $company->id(), 'Seehaus Erfunden AG'), $venues[$linked->id()]);
        self::assertEquals(new CustomerMatchVenue($alone->id(), 'Waldhaus Bärenegg', 'waldhaus baerenegg', null, null, null, null), $venues[$alone->id()]);
    }

    public function test_companies_come_with_their_eintrags_canton(): void
    {
        $zurich = CompanyFactory::new()->withVenue(VenueFactory::new()->withoutLocation($this->canton('zh'))->create())->create(['name' => 'Zunft Erfunden']);
        $nowhere = CompanyFactory::new()->withVenue(VenueFactory::new()->withoutLocation(null)->create())->create(['name' => 'Ort Erfunden']);
        $plain = CompanyFactory::new()->afterInstantiate(static function (Company $company): void {
            $company->changeWebsite('https://buehne-fiktiv.example.ch');
            $company->changeEmail('Info@Buehne-Fiktiv.example.ch');
        })->create(['name' => 'Bühne Fiktiv GmbH']);

        $companies = $this->byId($this->query()->companies());

        self::assertSame(['zh'], $companies[$zurich->id()]->cantons);
        self::assertSame([], $companies[$nowhere->id()]->cantons);
        self::assertEquals(new CustomerMatchCompany($plain->id(), 'Bühne Fiktiv GmbH', 'buehne fiktiv gmbh', 'https://buehne-fiktiv.example.ch', 'info@buehne-fiktiv.example.ch', []), $companies[$plain->id()]);
    }

    public function test_contact_emails_leave_out_contacts_without_one(): void
    {
        ContactFactory::createOne(['email' => 'erika@fiktiv.example.ch']);
        ContactFactory::createOne(['email' => null]);

        self::assertContains('erika@fiktiv.example.ch', $this->query()->contactEmails());
        self::assertNotContains('', $this->query()->contactEmails());
    }

    public function test_similar_names_of_venues_and_companies_in_one_statement(): void
    {
        VenueFactory::new()->withoutLocation(null)->create(['name' => 'Landgasthof Sonnenberg']);
        CompanyFactory::createOne(['name' => 'Landgasthof Sonnenberg AG']);
        VenueFactory::new()->withoutLocation(null)->create(['name' => 'Remise Ganzanders']);
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        $holder->reset();

        $similar = $this->query()->similar(['Landgasthof Sonnenberg', 'Landgasthof Sonneberg', 'Nichts Ähnliches']);

        self::assertCount(1, $holder->getData()['default'] ?? []);
        self::assertSame(['Landgasthof Sonnenberg', 'Landgasthof Sonnenberg AG'], $similar['Landgasthof Sonnenberg']);
        self::assertContains('Landgasthof Sonnenberg', $similar['Landgasthof Sonneberg']);
        self::assertArrayNotHasKey('Nichts Ähnliches', $similar);
    }

    private function canton(string $code): Canton
    {
        $canton = repository(Canton::class)->find($code);
        self::assertInstanceOf(Canton::class, $canton);

        return $canton;
    }

    /**
     * @template T of CustomerMatchVenue|CustomerMatchCompany
     *
     * @param list<T> $views
     *
     * @return array<string, T>
     */
    private function byId(array $views): array
    {
        $byId = [];
        foreach ($views as $view) {
            $byId[$view->id] = $view;
        }

        return $byId;
    }

    private function query(): CustomerMatchQuery
    {
        // Built by hand: until the runner (Step 4.2) uses it, the container drops the unused service.
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return new CustomerMatchQuery($connection);
    }
}
