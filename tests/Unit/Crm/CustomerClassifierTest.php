<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\CustomerClassifier;
use App\Crm\CustomerRecordMapper;
use App\Crm\ReadModel\CustomerMatchCompany;
use App\Crm\ReadModel\CustomerMatchIndex;
use App\Crm\ReadModel\CustomerMatchVenue;
use App\Crm\ValueObject\CustomerClassification;
use App\Crm\ValueObject\CustomerRow;
use App\Crm\ValueObject\SheetRegion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #48 R4–R6 on fictional rows (Step 2.4). The keys stand in for `geo_fold_de()`: `fold()` below gives the
 * same keys as the SQL function for these ASCII and umlaut names (`CustomerMatchQueryTest` covers the SQL).
 */
final class CustomerClassifierTest extends TestCase
{
    public function test_one_name_in_three_cantons_with_one_host_is_three_new_places_named_by_region(): void
    {
        $out = $this->classify([
            self::row(2, 'Hotel Erfunden', 'Basel', 'https://www.hotel-erfunden.example.com/basel'),
            self::row(3, 'Hotel Erfunden', 'Luzern', 'hotel-erfunden.example.com/luzern'),
            self::row(4, 'Hotel Erfunden', 'St. Gallen', 'https://hotel-erfunden.example.com'),
        ]);

        self::assertSame([
            [2, 'new', 'Hotel Erfunden (Basel)'],
            [3, 'new', 'Hotel Erfunden (Luzern)'],
            [4, 'new', 'Hotel Erfunden (St. Gallen)'],
        ], self::summary($out));
        self::assertSame('Hotel Erfunden (Luzern)', $out[1]->record?->name);
        self::assertTrue($out[1]->isNamedApart());
    }

    public function test_a_canton_and_its_wider_region_with_one_host_are_one_place_and_a_duplicate(): void
    {
        $out = $this->classify([
            self::row(2, 'Tierpark Fiktiv', 'Schwyz', 'https://tierpark-fiktiv.example.ch', mailSent: '04.01.2024'),
            self::row(3, 'Tierpark Fiktiv', 'Zentralschweiz', 'https://www.tierpark-fiktiv.example.ch/', mailSent: '22.07.2024'),
        ]);

        self::assertSame([[2, 'new', 'Tierpark Fiktiv'], [3, 'duplicate in sheet', 'Tierpark Fiktiv']], self::summary($out));
        self::assertSame([2, 3], $out[0]->rows);
        self::assertSame([2, 3], $out[1]->rows);
        self::assertSame('same host', $out[1]->reason);
        self::assertSame([2, 3], $out[0]->record?->rows);
        self::assertCount(2, $out[0]->record->mailDates);
    }

    public function test_one_name_twice_in_one_canton_with_two_hosts_is_named_by_host(): void
    {
        $out = $this->classify([
            self::row(2, 'Landgasthof Fiktiv', 'Bern', 'https://landgasthof-fiktiv-a.example.ch'),
            self::row(3, 'Landgasthof Fiktiv', 'Bern', 'https://landgasthof-fiktiv-b.example.ch'),
        ]);

        self::assertSame([
            [2, 'new', 'Landgasthof Fiktiv (landgasthof-fiktiv-a.example.ch)'],
            [3, 'new', 'Landgasthof Fiktiv (landgasthof-fiktiv-b.example.ch)'],
        ], self::summary($out));
    }

    public function test_one_name_twice_in_one_canton_without_hosts_is_a_repeated_name(): void
    {
        $out = $this->classify([
            self::row(2, 'Gasthof Erfunden', 'Bern', null, 'info@gasthof-a.example.ch'),
            self::row(5, 'Gasthof Erfunden', 'Bern', null, 'info@gasthof-b.example.ch'),
        ]);

        self::assertSame([[2, 'name repeated', null]], self::summary($out));
        self::assertSame([2, 5], $out[0]->rows);
        self::assertSame('suffix collision', $out[0]->reason);
        self::assertNull($out[0]->record);
    }

    public function test_a_place_with_neither_region_nor_host_makes_its_name_repeated(): void
    {
        $out = $this->classify([
            self::row(2, 'Gasthof Erfunden', 'Bern', 'https://gasthof-erfunden.example.ch'),
            self::row(3, 'Gasthof Erfunden', '', null, 'info@gasthof-b.example.ch'),
        ]);

        self::assertSame([[2, 'name repeated', null]], self::summary($out));
        self::assertSame('no region or host', $out[0]->reason);
    }

    public function test_a_suffixed_name_meeting_another_sheet_name_makes_both_repeated(): void
    {
        $out = $this->classify([
            self::row(2, 'Villa Fiktiv', 'Zürich', 'https://villa-fiktiv-zh.example.ch'),
            self::row(3, 'Villa Fiktiv', 'Bern', 'https://villa-fiktiv-be.example.ch'),
            self::row(4, 'Villa Fiktiv, Zürich', 'Zürich', 'https://villa-fiktiv-zuerich.example.ch'),
        ]);

        self::assertSame([[2, 'name repeated', null], [3, 'new', 'Villa Fiktiv (Bern)']], self::summary($out));
        self::assertSame([2, 4], $out[0]->rows);
    }

    public function test_a_name_with_its_place_matches_the_venue_of_its_base_in_a_fitting_canton(): void
    {
        $venue = self::venue('Schlossgut Erfunden', 'ag', 'Schlossgut Erfunden AG');

        $out = $this->classify([self::row(2, 'Schlossgut Erfunden, Beispielwil', 'Aargau')], venues: [$venue]);

        self::assertSame([[2, 'matched (venue)', 'Schlossgut Erfunden, Beispielwil']], self::summary($out));
        self::assertSame('Schlossgut Erfunden', $out[0]->candidate);
        self::assertSame('same base name', $out[0]->reason);
        self::assertNull($out[0]->record);
    }

    public function test_a_name_with_its_place_is_new_when_the_venue_of_its_base_is_in_another_canton(): void
    {
        $venue = self::venue('Schlossgut Erfunden', 'be', 'Schlossgut Erfunden AG');

        $out = $this->classify([self::row(2, 'Schlossgut Erfunden, Beispielwil', 'Aargau')], venues: [$venue]);

        self::assertSame([[2, 'new', 'Schlossgut Erfunden, Beispielwil']], self::summary($out));
    }

    public function test_a_name_without_place_and_a_base_hit_of_unknown_place_is_to_check(): void
    {
        $company = self::company('Schlossgut Erfunden');

        $out = $this->classify([self::row(2, 'Schlossgut Erfunden, Beispielwil', 'Aargau')], companies: [$company]);

        self::assertSame([[2, 'to check', 'Schlossgut Erfunden, Beispielwil']], self::summary($out));
        self::assertSame('name without place', $out[0]->reason);
        self::assertSame('Schlossgut Erfunden', $out[0]->candidate);
    }

    public function test_a_full_name_hit_only_in_another_canton_is_new_and_named_by_region(): void
    {
        $venue = self::venue('Seehaus Fiktiv', 'be', 'Seehaus Fiktiv AG');

        $out = $this->classify([self::row(2, 'Seehaus Fiktiv', 'Zürich')], venues: [$venue]);

        self::assertSame([[2, 'new', 'Seehaus Fiktiv (Zürich)']], self::summary($out));
        self::assertSame('other canton', $out[0]->reason);
        self::assertSame('Seehaus Fiktiv', $out[0]->candidate);
        self::assertSame('Seehaus Fiktiv (Zürich)', $out[0]->record?->name);
    }

    public function test_a_liechtenstein_row_matches_a_liechtenstein_venue_of_the_same_name(): void
    {
        // #46: Liechtenstein venues carry `li`, so the row's region fits them.
        $out = $this->classify([self::row(2, 'Rheinhaus Fiktiv', 'Liechtenstein')], venues: [self::venue('Rheinhaus Fiktiv', 'li', 'Rheinhaus Fiktiv AG')]);

        self::assertSame([[2, 'matched (venue)', 'Rheinhaus Fiktiv']], self::summary($out));
        self::assertSame('same name', $out[0]->reason);

        // A Swiss canton next door still does not fit.
        $out = $this->classify([self::row(2, 'Rheinhaus Fiktiv', 'Liechtenstein')], venues: [self::venue('Rheinhaus Fiktiv', 'sg', 'Rheinhaus Fiktiv AG')]);

        self::assertSame([[2, 'new', 'Rheinhaus Fiktiv (Liechtenstein)']], self::summary($out));
        self::assertSame('other canton', $out[0]->reason);
    }

    public function test_a_name_suffixed_for_a_namesake_elsewhere_keeps_its_suffix_for_its_duplicates(): void
    {
        $venue = self::venue('Seehaus Fiktiv', 'be', 'Seehaus Fiktiv AG');

        $out = $this->classify([
            self::row(2, 'Seehaus Fiktiv', 'Zürich', 'https://seehaus-fiktiv.example.ch'),
            self::row(3, 'Seehaus Fiktiv', 'Zürich', 'https://seehaus-fiktiv.example.ch'),
        ], venues: [$venue]);

        self::assertSame([[2, 'new', 'Seehaus Fiktiv (Zürich)'], [3, 'duplicate in sheet', 'Seehaus Fiktiv (Zürich)']], self::summary($out));
    }

    public function test_two_new_places_of_one_final_key_after_a_region_suffix_are_repeated(): void
    {
        $venue = self::venue('Seehaus Fiktiv', 'be', 'Seehaus Fiktiv AG');

        $out = $this->classify([
            self::row(2, 'Seehaus Fiktiv', 'Zürich', 'https://seehaus-fiktiv-a.example.ch'),
            self::row(3, 'Seehaus Fiktiv, Zürich', 'Zürich', 'https://seehaus-fiktiv-b.example.ch'),
        ], venues: [$venue]);

        self::assertSame([[2, 'name repeated', null]], self::summary($out));
        self::assertSame([2, 3], $out[0]->rows);
        self::assertSame('suffix collision', $out[0]->reason);
    }

    public function test_the_rerun_finds_the_company_the_first_run_named_by_region(): void
    {
        $venue = self::venue('Seehaus Fiktiv', 'be', 'Seehaus Fiktiv AG');
        $created = self::company('Seehaus Fiktiv (Zürich)');

        $out = $this->classify([self::row(2, 'Seehaus Fiktiv', 'Zürich')], venues: [$venue], companies: [$created]);

        self::assertSame([[2, 'matched (name)', 'Seehaus Fiktiv (Zürich)']], self::summary($out));
        self::assertSame('Seehaus Fiktiv (Zürich)', $out[0]->candidate);
    }

    public function test_a_full_name_hit_on_a_venue_in_a_fitting_canton_is_matched_by_venue(): void
    {
        $out = $this->classify([self::row(2, 'Seehaus Fiktiv', 'Bern')], venues: [self::venue('Seehaus Fiktiv', 'be', 'Seehaus Fiktiv AG')]);

        self::assertSame([[2, 'matched (venue)', 'Seehaus Fiktiv']], self::summary($out));
        self::assertSame('same name', $out[0]->reason);
    }

    public function test_a_venue_without_a_company_blocks_the_row(): void
    {
        $out = $this->classify([self::row(2, 'Remise Erfunden', 'Bern')], venues: [self::venue('Remise Erfunden', 'be', null)]);

        self::assertSame([[2, 'blocked', 'Remise Erfunden']], self::summary($out));
        self::assertSame('venue without company', $out[0]->reason);
        self::assertSame('Remise Erfunden', $out[0]->candidate);
    }

    public function test_two_companies_of_the_key_are_ambiguous(): void
    {
        $out = $this->classify([self::row(2, 'Gutshof Fiktiv', 'Bern')], companies: [self::company('Gutshof Fiktiv'), self::company('GUTSHOF FIKTIV')]);

        self::assertSame([[2, 'ambiguous', 'Gutshof Fiktiv']], self::summary($out));
        self::assertSame('several companies', $out[0]->reason);
    }

    /**
     * @return iterable<string, array{CustomerRow, list<CustomerMatchVenue>, list<CustomerMatchCompany>, list<string>, array<string, list<string>>, string, ?string}>
     */
    public static function secondarySignals(): iterable
    {
        yield 'host of a venue' => [
            self::row(2, 'Waldhaus Neuname', 'Bern', 'https://www.waldhaus-alt.example.ch/hochzeit'),
            [self::venue('Waldhaus Altname', 'be', 'Waldhaus AG', 'https://waldhaus-alt.example.ch')], [], [], [],
            'same host', 'Waldhaus Altname',
        ];
        yield 'email of a company' => [
            self::row(2, 'Waldhaus Neuname', 'Bern', null, 'Info@Waldhaus.example.ch; hallo@waldhaus.example.ch'),
            [], [self::company('Waldhaus Altname', email: 'hallo@waldhaus.example.ch')], [], [],
            'same email', 'Waldhaus Altname',
        ];
        yield 'email of a contact, never named' => [
            self::row(2, 'Waldhaus Neuname', 'Bern', null, 'erika@waldhaus.example.ch'),
            [], [], ['erika@waldhaus.example.ch'], [],
            'same email', null,
        ];
        yield 'similar name' => [
            self::row(2, 'Waldhaus Neuname', 'Bern'),
            [self::venue('Waldhaus Neunamen', null, null)], [], [], ['Waldhaus Neuname' => ['Waldhaus Neunamen']],
            'similar name', 'Waldhaus Neunamen',
        ];
    }

    /**
     * @param list<CustomerMatchVenue>    $venues
     * @param list<CustomerMatchCompany>  $companies
     * @param list<string>                $contactEmails
     * @param array<string, list<string>> $similar
     */
    #[DataProvider('secondarySignals')]
    public function test_a_secondary_signal_is_to_check_with_its_reason(CustomerRow $row, array $venues, array $companies, array $contactEmails, array $similar, string $reason, ?string $candidate): void
    {
        $out = $this->classify([$row], $venues, $companies, $contactEmails, $similar);

        self::assertSame([[2, 'to check', 'Waldhaus Neuname']], self::summary($out));
        self::assertSame($reason, $out[0]->reason);
        self::assertSame($candidate, $out[0]->candidate);
    }

    public function test_a_secondary_signal_from_another_canton_is_ignored(): void
    {
        $out = $this->classify(
            [self::row(2, 'Waldhaus Neuname', 'Zürich', 'https://waldhaus-alt.example.ch')],
            venues: [self::venue('Waldhaus Altname', 'be', 'Waldhaus AG', 'https://waldhaus-alt.example.ch'), self::venue('Waldhaus Neunamen', 'be', null)],
            similar: ['Waldhaus Neuname' => ['Waldhaus Neunamen']],
        );

        self::assertSame([[2, 'new', 'Waldhaus Neuname']], self::summary($out));
    }

    public function test_an_empty_name_is_invalid_and_the_output_is_in_row_order(): void
    {
        $out = $this->classify([
            self::row(2, 'Zweites Haus', 'Bern'),
            self::row(3, null, 'Bern'),
            self::row(4, 'Erstes Haus', 'Bern'),
        ]);

        self::assertSame([[2, 'new', 'Zweites Haus'], [3, 'invalid', null], [4, 'new', 'Erstes Haus']], self::summary($out));
        self::assertSame('empty name', $out[1]->reason);
    }

    public function test_the_key_candidates_name_names_bases_regions_and_hosts(): void
    {
        $candidates = CustomerClassifier::keyCandidates([
            self::row(2, 'Schlossgut Erfunden, Beispielwil', 'Aargau', 'https://www.schlossgut.example.ch/x'),
            self::row(3, 'Hof Fiktiv', 'Nirgendwo'),
            self::row(4, null, 'Bern'),
        ]);

        self::assertSame(['Schlossgut Erfunden, Beispielwil', 'Schlossgut Erfunden', 'Aargau', 'schlossgut.example.ch', 'Hof Fiktiv'], $candidates);
        self::assertSame(['Hof Fiktiv'], CustomerClassifier::similarNames([self::row(3, 'Hof Fiktiv', ''), self::row(4, 'Hof Fiktiv', ''), self::row(5, null, '')]));
    }

    /**
     * @param list<CustomerRow>           $rows
     * @param list<CustomerMatchVenue>    $venues
     * @param list<CustomerMatchCompany>  $companies
     * @param list<string>                $contactEmails
     * @param array<string, list<string>> $similar
     *
     * @return list<CustomerClassification>
     */
    private function classify(array $rows, array $venues = [], array $companies = [], array $contactEmails = [], array $similar = []): array
    {
        $keys = [];
        foreach (CustomerClassifier::keyCandidates($rows) as $text) {
            $keys[$text] = self::fold($text);
        }

        return (new CustomerClassifier(new CustomerRecordMapper()))->classify($rows, new CustomerMatchIndex($venues, $companies, $contactEmails, $similar), $keys);
    }

    /**
     * @param list<CustomerClassification> $out
     *
     * @return list<array{int, string, ?string}>
     */
    private static function summary(array $out): array
    {
        return array_map(static fn (CustomerClassification $c): array => [$c->row(), $c->class->value, $c->finalName], $out);
    }

    private static function row(int $number, ?string $name, string $region, ?string $website = null, ?string $email = null, ?string $mailSent = null): CustomerRow
    {
        return new CustomerRow($number, $name, 'keine Antwort', null, 'Erika', 'Fiktiv', $website, $email, null, null, $mailSent, null, SheetRegion::fromSheet($region));
    }

    private static function venue(string $name, ?string $canton, ?string $companyName, ?string $website = null): CustomerMatchVenue
    {
        return new CustomerMatchVenue('v-'.$name, $name, self::fold($name), $canton, $website, null === $companyName ? null : 'c-'.$companyName, $companyName);
    }

    /**
     * @param list<string> $cantons
     */
    private static function company(string $name, array $cantons = [], ?string $email = null): CustomerMatchCompany
    {
        return new CustomerMatchCompany('c-'.$name, $name, self::fold($name), null, $email, $cantons);
    }

    /** `geo_fold_de()` for the names of this test. */
    private static function fold(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue']);
        $text = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($text, \Normalizer::FORM_D));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
    }
}
