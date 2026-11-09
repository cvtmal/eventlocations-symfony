<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalVenueAccessFactory;
use App\Portal\Entity\PortalUser;
use App\Portal\ValueObject\LocationCard;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Tests\Support\Portal\PortalSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Command\AnswerVenueFaq;
use App\Venues\Command\ChangeVenueCapacity;
use App\Venues\Command\ChangeVenueContact;
use App\Venues\Command\ChangeVenueEventFacts;
use App\Venues\Command\ChangeVenueTerms;
use App\Venues\Command\ChangeVenueTexts;
use App\Venues\Command\RelocateVenue;
use App\Venues\Entity\Venue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * Every card of the manager's editor through its real form (POR-4, POR-8, POR-9), and what stays with staff: a
 * crafted field is refused by the form, a staff card has no route.
 */
final class LocationCardsTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use PortalSession;

    private const string NEW_SUMMARY = 'Ein neuer Kurztext.';
    private const array TEXTS_CHANGE = ['texts' => ['de' => ['summary' => self::NEW_SUMMARY]]];
    private const array CAPACITY_CHANGE = ['capacityMax' => '95'];
    private const array EVENT_CHANGE = ['capacitySeated' => '60'];

    /**
     * @return iterable<string, array{LocationCard, array<string, mixed>, class-string, string}>
     */
    public static function cards(): iterable
    {
        yield 'texts' => [LocationCard::Texts, self::TEXTS_CHANGE, ChangeVenueTexts::class, 'texts.de.summary'];
        yield 'address' => [LocationCard::Address, ['street' => 'Seeweg 3'], RelocateVenue::class, 'street'];
        yield 'terms' => [LocationCard::Terms, ['setting' => ['lake']], ChangeVenueTerms::class, 'terms.setting'];
        yield 'event' => [LocationCard::Event, self::EVENT_CHANGE, ChangeVenueEventFacts::class, 'capacitySeated'];
        yield 'contact' => [LocationCard::Contact, ['contactPerson' => 'Anna Muster'], ChangeVenueContact::class, 'contactPerson'];
        yield 'faq' => [LocationCard::Faq, ['daylight' => 'yes'], AnswerVenueFaq::class, 'daylight'];
        yield 'capacity' => [LocationCard::Capacity, self::CAPACITY_CHANGE, ChangeVenueCapacity::class, 'capacityMax'];
    }

    public function test_the_provider_covers_every_card(): void
    {
        $covered = array_map(static fn (array $row): LocationCard => $row[0], array_values(iterator_to_array(self::cards())));

        self::assertSame(LocationCard::cases(), $covered);
    }

    /**
     * @param array<string, mixed> $change
     * @param class-string         $command
     */
    #[DataProvider('cards')]
    public function test_a_card_saves_marks_purges_and_audits_the_manager(LocationCard $card, array $change, string $command, string $changedField): void
    {
        $manager = $this->logInAsManager();
        $venue = $this->managedVenue($manager);

        $this->post($card, $venue, $change);

        self::assertResponseRedirects($this->url($venue).'#'.$card->anchor());
        self::assertNotNull($this->connection->fetchOne('SELECT cms_edited_at FROM venues WHERE id = ?', [$venue->id()]));
        self::assertSame([$venue->number()], $this->purger()->venues);

        $rows = $this->connection->fetchAllAssociative("SELECT actor_type, portal_user_id, command, changes FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $venue->number()]);
        self::assertCount(1, $rows);
        self::assertSame(['portal_user', $manager->id(), $command], [$rows[0]['actor_type'], $rows[0]['portal_user_id'], $rows[0]['command']]);
        $changes = json_decode((string) $rows[0]['changes'], true);
        self::assertIsArray($changes);
        self::assertSame([$changedField], $changes['changed']);
    }

    public function test_the_maximum_reaches_the_venue_and_leaves_the_minimum(): void
    {
        $venue = $this->managedVenue($this->logInAsManager());
        $this->connection->executeStatement('UPDATE venues SET capacity_min = 40 WHERE id = ?', [$venue->id()]);

        $this->post(LocationCard::Capacity, $venue, self::CAPACITY_CHANGE);

        self::assertResponseRedirects();
        self::assertSame([40, 95], $this->connection->fetchNumeric('SELECT capacity_min, capacity_max FROM venues WHERE id = ?', [$venue->id()]));
    }

    public function test_an_unchanged_save_leaves_no_trace(): void
    {
        $venue = $this->managedVenue($this->logInAsManager());

        $this->post(LocationCard::Texts, $venue);

        self::assertResponseRedirects($this->url($venue).'#texte');
        self::assertSame(0, $this->auditRows($venue));
        self::assertNull($this->connection->fetchOne('SELECT cms_edited_at FROM venues WHERE id = ?', [$venue->id()]));
        self::assertSame([], $this->purger()->venues);
    }

    /**
     * The old `UrlType` stored a dotted handle typed without `@` as `https://remise.rebhalde`, which names no profile.
     * Sent back untouched it stays as stored and blocks nothing; only a changed value is checked (small-ux-followups F-M1).
     */
    public function test_an_untouched_unreadable_instagram_does_not_block_the_contact_card(): void
    {
        $venue = $this->managedVenue($this->logInAsManager());
        $this->connection->executeStatement("UPDATE venues SET instagram = 'https://remise.rebhalde' WHERE id = ?", [$venue->id()]);

        $this->post(LocationCard::Contact, $venue, ['contactPerson' => 'Anna Muster']);

        self::assertResponseRedirects($this->url($venue).'#'.LocationCard::Contact->anchor());
        self::assertSame(['Anna Muster', 'https://remise.rebhalde'], $this->connection->fetchNumeric('SELECT contact_person, instagram FROM venues WHERE id = ?', [$venue->id()]));
        $changes = json_decode((string) $this->connection->fetchOne("SELECT changes FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $venue->number()]), true);
        self::assertIsArray($changes);
        self::assertSame(['contactPerson'], $changes['changed']);
    }

    public function test_a_changed_instagram_that_names_no_profile_is_refused(): void
    {
        $venue = $this->managedVenue($this->logInAsManager());
        $this->connection->executeStatement("UPDATE venues SET instagram = 'https://remise.rebhalde' WHERE id = ?", [$venue->id()]);

        $crawler = $this->post(LocationCard::Contact, $venue, ['instagram' => 'https://www.remise.example', 'contactPerson' => 'Anna Muster']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('true', $crawler->filter('#venue_contact_instagram')->attr('aria-invalid'));
        self::assertSame([null, 'https://remise.rebhalde'], $this->connection->fetchNumeric('SELECT contact_person, instagram FROM venues WHERE id = ?', [$venue->id()]));
    }

    /**
     * D60: an online location keeps its inquiry address; the save is refused and the field says why.
     */
    public function test_an_online_location_keeps_its_inquiry_address(): void
    {
        $venue = $this->managedVenue($this->logInAsManager());
        $crawler = $this->post(LocationCard::Contact, $venue, ['inquiryEmail' => '', 'contactPerson' => 'Anna Muster']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('true', $crawler->filter('#venue_contact_inquiryEmail')->attr('aria-invalid'));
        self::assertStringContainsString('Eine Location, die online ist, braucht eine Anfrage-E-Mail.', $crawler->filter('form[name="venue_contact"]')->text());
        self::assertSame(['published', 'anfragen@gartenhaus.example', null], $this->connection->fetchNumeric('SELECT status, inquiry_email, contact_person FROM venues WHERE id = ?', [$venue->id()]));
    }

    /**
     * One crafted field per case: together, a field that had slipped into the form would hide behind the others.
     *
     * @return iterable<string, array{LocationCard, array<string, mixed>, array<string, mixed>}>
     */
    public static function craftedFields(): iterable
    {
        yield 'name' => [LocationCard::Texts, self::TEXTS_CHANGE, ['name' => 'Neuer Name']];
        yield 'slug' => [LocationCard::Texts, self::TEXTS_CHANGE, ['slug' => 'neuer-slug']];
        yield 'tier' => [LocationCard::Texts, self::TEXTS_CHANGE, ['tier' => 'pro']];
        yield 'the slug of a language' => [LocationCard::Texts, self::TEXTS_CHANGE, ['texts' => ['de' => ['slug' => 'neuer-slug']]]];
        yield 'minimum' => [LocationCard::Capacity, self::CAPACITY_CHANGE, ['capacityMin' => '10']];
        yield 'the party rule of the old wedding card' => [LocationCard::Event, self::EVENT_CHANGE, ['party' => 'yes']];
        yield 'the curfew of the old wedding card' => [LocationCard::Event, self::EVENT_CHANGE, ['hasCurfew' => 'yes']];
        yield 'the curfew time of the old wedding card' => [LocationCard::Event, self::EVENT_CHANGE, ['curfewAt' => '23:00']];
        yield 'the party note of the old wedding card' => [LocationCard::Event, self::EVENT_CHANGE, ['texts' => ['de' => ['partyNote' => 'Bis 2 Uhr.']]]];
    }

    /**
     * @param array<string, mixed> $change
     * @param array<string, mixed> $crafted
     */
    #[DataProvider('craftedFields')]
    public function test_a_crafted_field_is_a_422_and_changes_nothing(LocationCard $card, array $change, array $crafted): void
    {
        $venue = $this->managedVenue($this->logInAsManager());
        $before = $this->staffFields($venue);

        $this->post($card, $venue, array_replace_recursive($change, $crafted));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame($before, $this->staffFields($venue));
        self::assertSame(0, $this->auditRows($venue));
    }

    public function test_the_same_posts_without_a_crafted_field_save(): void
    {
        $venue = $this->managedVenue($this->logInAsManager());

        $this->post(LocationCard::Texts, $venue, self::TEXTS_CHANGE);
        self::assertResponseRedirects($this->url($venue).'#texte');

        $this->post(LocationCard::Capacity, $venue, self::CAPACITY_CHANGE);
        self::assertResponseRedirects($this->url($venue).'#gaeste');

        self::assertSame(2, $this->auditRows($venue));
    }

    public function test_a_staff_write_has_no_route(): void
    {
        $venue = $this->managedVenue($this->logInAsManager());
        $url = $this->url($venue);
        $crawler = $this->client->request('GET', $url);
        $token = (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
        $before = $this->staffFields($venue);

        foreach (['stammdaten', 'bewertung', 'loeschen'] as $segment) {
            $this->client->request('POST', $url.'/'.$segment, ['venue_basics' => ['name' => 'Neuer Name']]);

            self::assertContains($this->client->getResponse()->getStatusCode(), [Response::HTTP_NOT_FOUND, Response::HTTP_METHOD_NOT_ALLOWED], $segment);
        }

        $this->client->request('PUT', '/konto/api/locations/'.$venue->number().'/photos/0199f0d0-0000-7000-8000-000000000001/room', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: '{"roomId":null}');

        self::assertContains($this->client->getResponse()->getStatusCode(), [Response::HTTP_NOT_FOUND, Response::HTTP_METHOD_NOT_ALLOWED]);
        self::assertSame($before, $this->staffFields($venue));
    }

    public function test_a_stale_save_is_a_409_that_keeps_the_input_and_links_to_the_card(): void
    {
        $venue = $this->managedVenue($this->logInAsManager());
        $url = $this->url($venue);

        $crawler = $this->client->request('GET', $url);
        $form = $crawler->filter('form[name="venue_texts"]')->form();
        $this->connection->executeStatement("UPDATE venues SET updated_at = updated_at + INTERVAL '1 hour' WHERE id = ?", [$venue->id()]);
        $crawler = $this->client->submit($form, ['venue_texts[texts][de][summary]' => self::NEW_SUMMARY]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(self::NEW_SUMMARY, $crawler->filter('#venue_texts_texts_de_summary')->text());
        self::assertStringContainsString('Diese Location wurde inzwischen geändert', $crawler->filter('#texte')->text());
        self::assertSame($url.'#texte', $crawler->filter('#texte .venue-card__stale a')->attr('href'));
        self::assertSame('Ein kleines Gartenhaus.', $this->connection->fetchOne("SELECT summary FROM venue_translations WHERE venue_id = ? AND locale = 'de'", [$venue->id()]));
        self::assertSame(0, $this->auditRows($venue));
    }

    /**
     * Sends what a browser would, plus `$fields` over it. Not `submit()`: a crafted field is no form field.
     *
     * @param array<string, mixed> $fields
     */
    private function post(LocationCard $card, Venue $venue, array $fields = []): Crawler
    {
        $crawler = $this->client->request('GET', $this->url($venue));
        $form = $crawler->filter('form[name="'.$card->formName().'"]')->form();

        return $this->client->request('POST', $form->getUri(), array_replace_recursive($form->getPhpValues(), [$card->formName() => $fields]));
    }

    private function managedVenue(PortalUser $manager): Venue
    {
        $venue = DetailVenues::minimal();
        // D60: an online location keeps its inquiry address, so a contact save needs one to start from.
        $this->connection->executeStatement("UPDATE venues SET inquiry_email = 'anfragen@gartenhaus.example' WHERE id = ?", [$venue->id()]);
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);

        return $venue;
    }

    /**
     * @return array<string, mixed>
     */
    private function staffFields(Venue $venue): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT v.name, v.tier_id, v.capacity_min, v.capacity_max, v.cms_edited_at, t.slug, t.summary
                FROM venues v
                JOIN venue_translations t ON t.venue_id = v.id AND t.locale = 'de'
                WHERE v.id = ?
                SQL,
            [$venue->id()],
        );
        self::assertIsArray($row);

        return $row;
    }

    private function auditRows(Venue $venue): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $venue->number()]);
    }

    private function url(Venue $venue): string
    {
        return '/konto/locations/'.$venue->number();
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }
}
