<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Factory\VenueTermFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Command\ChangeVenueEventFacts;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\TermGroup;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The card "Veranstaltung" (event-vocabulary, F3): price level, seated and standing capacity, the three texts of a
 * language, and the chips of the groups amenity, catering and language. The card owns exactly those groups, so a
 * save never touches the filter groups of "Merkmale" and the other way round. Party, curfew and the party note
 * are gone with the wedding card.
 */
final class VenueEventCardTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ImportsTownDataset;

    private const array EVENT_GROUPS = ['amenity', 'catering', 'language'];

    public function test_the_card_offers_the_event_fields_and_every_term_of_its_three_groups(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue();

        $crawler = $this->editor($venue);

        $form = $crawler->filter('form[name="venue_event"]');
        self::assertCount(1, $form);
        self::assertSame('veranstaltung', $form->attr('id'));
        self::assertSame($this->url($venue).'/veranstaltung', $form->attr('action'));
        self::assertSame('Veranstaltung', $crawler->filter('#venue-card-event')->text());
        foreach (['priceLevel', 'capacitySeated', 'capacityStanding', 'texts][de][ambience', 'texts][de][capacityNote', 'texts][de][openingHours'] as $field) {
            self::assertGreaterThan(0, $form->filter('[name="venue_event['.$field.']"]')->count(), $field);
        }
        foreach (['party', 'hasCurfew', 'curfewAt', 'texts][de][partyNote'] as $field) {
            self::assertCount(0, $form->filter('[name="venue_event['.$field.']"]'), $field);
        }
        foreach (self::EVENT_GROUPS as $group) {
            $terms = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM taxonomy_terms WHERE term_group = ?', [$group]);
            self::assertGreaterThan(0, $terms, $group);
            self::assertCount($terms, $form->filter('input[name="venue_event['.$group.'][]"]'), $group);
        }
        self::assertSame(
            ['Preisniveau', 'Ausstattung', 'Catering', 'Sprachen'],
            $form->filter('fieldset > legend')->each(static fn (Crawler $legend): string => $legend->text()),
        );
    }

    public function test_the_wedding_card_is_gone(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue();

        $crawler = $this->editor($venue);

        self::assertCount(0, $crawler->filter('form[name="venue_wedding"]'));
        self::assertCount(0, $crawler->filter('#hochzeit'));
        $this->client->request('POST', $this->url($venue).'/hochzeit');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_save_stores_the_facts_audits_them_and_replaces_only_the_three_groups(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue();
        $filterTerms = $this->terms($venue, self::filterGroups());
        self::assertSame(['building.castle', 'setting.lake', 'suitable_for.corporate_event', 'technology.wifi'], $filterTerms);
        self::assertSame(['amenity.parking', 'catering.in_house', 'language.de'], $this->terms($venue, self::EVENT_GROUPS));

        $this->post($venue, [
            'priceLevel' => 'exclusive',
            'capacitySeated' => '300',
            'capacityStanding' => '450',
            'texts' => ['de' => ['ambience' => 'Festlich', 'capacityNote' => 'Mit Bühne 300 Plätze.', 'openingHours' => 'Täglich ab 9 Uhr']],
            'amenity' => ['overnight', 'parking'],
            'catering' => ['buffet', 'vegan'],
            'language' => [],
        ]);

        self::assertResponseRedirects($this->url($venue).'#veranstaltung');
        self::assertSame(['exclusive', 300, 450], $this->connection->fetchNumeric('SELECT price_level, capacity_seated, capacity_standing FROM venues WHERE id = ?', [$venue->id()]));
        self::assertSame(
            ['Festlich', 'Mit Bühne 300 Plätze.', 'Täglich ab 9 Uhr'],
            $this->connection->fetchNumeric("SELECT ambience, capacity_note, opening_hours FROM venue_translations WHERE venue_id = ? AND locale = 'de'", [$venue->id()]),
        );
        self::assertSame(['amenity.overnight', 'amenity.parking', 'catering.buffet', 'catering.vegan'], $this->terms($venue, self::EVENT_GROUPS));
        self::assertSame($filterTerms, $this->terms($venue, self::filterGroups()));
        self::assertNotNull($this->connection->fetchOne('SELECT cms_edited_at FROM venues WHERE id = ?', [$venue->id()]));

        $audit = $this->connection->fetchAssociative("SELECT command, changes FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $venue->number()]);
        self::assertIsArray($audit);
        self::assertSame(ChangeVenueEventFacts::class, $audit['command']);
        $changes = json_decode((string) $audit['changes'], true);
        self::assertIsArray($changes);
        self::assertSame(
            ['priceLevel', 'capacitySeated', 'capacityStanding', 'texts.de.ambience', 'texts.de.capacityNote', 'texts.de.openingHours', 'terms.amenity', 'terms.catering', 'terms.language'],
            $changes['changed'],
        );

        $crawler = $this->client->followRedirect();
        self::assertSame('«Veranstaltung» gespeichert.', $crawler->filter('.toast')->text());
        self::assertStringContainsString('hat die Veranstaltungsangaben geändert', $crawler->filter('section[aria-labelledby="venue-history"]')->text());
        self::assertSame('exclusive', $crawler->filter('input[name="venue_event[priceLevel]"][checked]')->attr('value'));
        self::assertSame(['300', '450'], [$crawler->filter('#venue_event_capacitySeated')->attr('value'), $crawler->filter('#venue_event_capacityStanding')->attr('value')]);
        self::assertSame('Täglich ab 9 Uhr', $crawler->filter('#venue_event_texts_de_openingHours')->text());
        self::assertSame(['buffet', 'vegan'], $crawler->filter('input[name="venue_event[catering][]"][checked]')->each(static fn (Crawler $box): string => (string) $box->attr('value')));
        self::assertCount(0, $crawler->filter('input[name="venue_event[language][]"][checked]'));
    }

    public function test_saving_the_filter_card_leaves_the_event_groups_alone(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue();
        $eventTerms = $this->terms($venue, self::EVENT_GROUPS);

        $crawler = $this->editor($venue);
        $form = $crawler->filter('form[name="venue_terms"]')->form();
        $values = $form->getPhpValues();
        $values['venue_terms']['setting'] = ['lake', 'river'];
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects($this->url($venue).'#merkmale');
        self::assertSame(['building.castle', 'setting.lake', 'setting.river', 'suitable_for.corporate_event', 'technology.wifi'], $this->terms($venue, self::filterGroups()));
        self::assertSame($eventTerms, $this->terms($venue, self::EVENT_GROUPS));
    }

    public function test_a_code_of_another_group_is_refused_and_nothing_changes(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue();
        $before = $this->terms($venue, self::EVENT_GROUPS);

        $crawler = $this->post($venue, ['catering' => ['castle']]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('#veranstaltung .field__error'));
        self::assertSame($before, $this->terms($venue, self::EVENT_GROUPS));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $venue->number()]));
    }

    /**
     * A minimal venue with a known term in every group of both cards, so a save's effect can be told exactly.
     */
    private function venue(): Venue
    {
        $venue = DetailVenues::minimal();
        foreach ([
            [TermGroup::Building, 'castle'],
            [TermGroup::Setting, 'lake'],
            [TermGroup::SuitableFor, 'corporate_event'],
            [TermGroup::Technology, 'wifi'],
            [TermGroup::Amenity, 'parking'],
            [TermGroup::Catering, 'in_house'],
            [TermGroup::Language, 'de'],
        ] as [$group, $code]) {
            VenueTermFactory::new()->term($group, $code)->create(['venue' => $venue]);
        }

        return $venue;
    }

    /**
     * @return list<string>
     */
    private static function filterGroups(): array
    {
        return array_values(array_diff(array_map(static fn (TermGroup $group): string => $group->value, TermGroup::cases()), self::EVENT_GROUPS));
    }

    /**
     * @param list<string> $groups
     *
     * @return list<string> `<group>.<code>`, sorted
     */
    private function terms(Venue $venue, array $groups): array
    {
        return $this->connection->fetchFirstColumn(
            "SELECT term_group || '.' || term_code FROM venue_terms WHERE venue_id = ? AND term_group = ANY (string_to_array(?, ',')) ORDER BY 1",
            [$venue->id(), implode(',', $groups)],
        );
    }

    private function url(Venue $venue): string
    {
        return '/admin/locations/'.$venue->number();
    }

    private function editor(Venue $venue): Crawler
    {
        $crawler = $this->client->request('GET', $this->url($venue));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * Sends what a browser would, with `$fields` laid over the card's form values (a list or a map is replaced whole).
     *
     * @param array<string, mixed> $fields
     */
    private function post(Venue $venue, array $fields): Crawler
    {
        $form = $this->editor($venue)->filter('form[name="venue_event"]')->form();
        $values = $form->getPhpValues();
        foreach ($fields as $name => $value) {
            $values['venue_event'][$name] = $value;
        }

        return $this->client->request('POST', $form->getUri(), $values);
    }
}
