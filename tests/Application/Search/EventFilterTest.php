<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\VenueFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\ValueObject\Canton;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Search\LandingFixtures;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Zenstruck\Foundry\Test\Factories;

/**
 * The filter-bar slot "Anlass" (F5 `filter-event`, E5): `event[]` on the taxonomy group suitable_for, OR inside the
 * group. Trigger and overlay, the hiding rule, the chips, the live-count form and its hidden state. Own venues on the
 * test's connection, rolled back afterwards.
 */
final class EventFilterTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use LandingFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_trigger_and_overlay_render_the_event_types_with_data_in_vocabulary_order(): void
    {
        $this->eventVenue('Saal Hochzeit', 'wedding');
        $this->eventVenue('Saal Apéro', 'apero', 'corporate_event');

        $crawler = $this->get('/eventlokale');

        self::assertSame('Anlass', $crawler->filter('.filter-bar [popovertarget="filter-event"]:not([popovertargetaction])')->text());
        self::assertCount(1, $crawler->filter('[data-overlay][popover]#filter-event'));
        self::assertSame('Was für einen Anlass planst du?', $crawler->filter('#filter-event h2')->text());
        self::assertSame(['Geeignet für'], $crawler->filter('#filter-event legend')->each(static fn (Crawler $legend): string => $legend->text()));
        self::assertSame(['corporate_event', 'apero', 'wedding'], $this->values($crawler));
        self::assertSame(
            ['Firmenanlass', 'Apéro', 'Hochzeit'],
            $crawler->filter('#filter-event-form input[name="event[]"] + label')->each(static fn (Crawler $label): string => $label->text()),
        );
        self::assertSame(['filter-more-event-corporate_event', 'filter-more-event-apero', 'filter-more-event-wedding'], $crawler->filter('#filter-event-form input[type="checkbox"]')->each(
            static fn (Crawler $input): string => (string) $input->attr('id'),
        ));
        self::assertCount(0, $crawler->filter('#filter-event-form input[checked]'));
        self::assertSame([], $this->chips($crawler));
    }

    public function test_the_triggers_follow_the_slot_order(): void
    {
        $this->eventVenue('Saal Seminar', 'seminar');

        $crawler = $this->get('/eventlokale');

        self::assertSame(
            ['filter-region', 'filter-event', 'filter-guests', 'filter-more'],
            $crawler->filter('.filter-bar [popovertarget]:not([popovertargetaction])')->each(static fn (Crawler $trigger): string => (string) $trigger->attr('popovertarget')),
        );
    }

    public function test_without_an_event_type_the_trigger_and_overlay_are_absent(): void
    {
        $venue = $this->venue('Saal Ohne');
        // Event facts of other groups do not switch the slot on.
        VenueTermFactory::new()->term(TermGroup::Catering, 'buffet')->create(['venue' => $venue]);

        $crawler = $this->get('/eventlokale');

        self::assertCount(0, $crawler->filter('[popovertarget="filter-event"]'));
        self::assertCount(0, $crawler->filter('#filter-event, #filter-event-form'));
        self::assertCount(1, $crawler->filter('#filter-guests'));
    }

    public function test_an_unpublished_venue_gives_no_data(): void
    {
        [$venue] = $this->inCanton(Canton::ZH, 1, VenueStatus::Unpublished);
        VenueTermFactory::new()->term(TermGroup::SuitableFor, 'seminar')->create(['venue' => $venue]);

        self::assertCount(0, $this->get('/eventlokale')->filter('#filter-event'));
    }

    public function test_an_active_type_without_data_keeps_the_slot_and_stays_ticked(): void
    {
        $this->venue('Saal Ohne');

        $crawler = $this->get('/eventlokale?event[]=birthday');

        self::assertSame([], $this->names($crawler));
        self::assertSame(['Geburtstag'], $this->chips($crawler));
        self::assertSame(['birthday'], $this->values($crawler));
        self::assertCount(1, $crawler->filter('#filter-more-event-birthday[checked]'));
        self::assertCount(0, $crawler->filter('#filter-event-form input[type="hidden"]'));
    }

    public function test_one_chip_per_active_type_with_its_remove_link(): void
    {
        $this->eventVenue('Saal Beides', 'seminar', 'wedding');

        $crawler = $this->get('/eventlokale?canton=zh&event[]=wedding&event[]=seminar&guests=80');

        self::assertSame(['Zürich', 'Seminar', 'Hochzeit', '80 Gäste'], $this->chips($crawler));
        $seminar = $crawler->filter('.filter-bar .chip')->eq(1);
        self::assertSame('/eventlokale?canton=zh&guests=80&event%5B0%5D=wedding', $seminar->attr('href'));
        self::assertSame('Filter Seminar entfernen', $seminar->attr('aria-label'));
        self::assertSame('/eventlokale?canton=zh&guests=80&event%5B0%5D=seminar', $crawler->filter('.filter-bar .chip')->eq(2)->attr('href'));
        self::assertCount(1, $crawler->filter('#filter-more-event-seminar[checked]'));
        self::assertCount(1, $crawler->filter('#filter-more-event-wedding[checked]'));
        self::assertSame('/eventlokale?canton=zh&guests=80', $crawler->filter('#filter-event a[data-overlay-clear]')->attr('href'));
        // The event type is its own slot: the "weitere Filter" pill does not count it.
        self::assertCount(0, $crawler->filter('.chip--more'));
    }

    public function test_two_types_widen_the_result(): void
    {
        $this->eventVenue('Saal Seminar', 'seminar');
        $this->eventVenue('Saal Hochzeit', 'wedding');
        $this->eventVenue('Saal Beides', 'seminar', 'wedding');
        $this->eventVenue('Saal Apéro', 'apero');
        $this->venue('Saal Ohne');

        self::assertSame(['Saal Beides', 'Saal Seminar'], $this->sortedNames($this->get('/eventlokale?event[]=seminar')));
        self::assertSame(
            ['Saal Beides', 'Saal Hochzeit', 'Saal Seminar'],
            $this->sortedNames($this->get('/eventlokale?event[]=seminar&event[]=wedding')),
        );
        self::assertCount(5, $this->get('/eventlokale')->filter('.card'));
    }

    public function test_unknown_codes_are_dropped(): void
    {
        $this->eventVenue('Saal Seminar', 'seminar');
        $this->venue('Saal Ohne');

        $crawler = $this->get('/eventlokale?event[]=nonsense&event[]=Seminar%20');

        self::assertSame([], $this->chips($crawler));
        self::assertCount(2, $crawler->filter('.card'));
        self::assertSame('http://localhost/eventlokale', $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    public function test_the_form_has_the_live_count_contract(): void
    {
        $this->eventVenue('Saal Seminar', 'seminar');

        $crawler = $this->get('/eventlokale');

        $form = $crawler->filter('form#filter-event-form.filter-more.filter-event');
        self::assertCount(1, $form);
        self::assertCount(1, $crawler->filter('#filter-event form'));
        self::assertSame('get', $form->attr('method'));
        self::assertSame('/eventlokale', $form->attr('action'));
        self::assertSame('off', $form->attr('autocomplete'));
        self::assertNotNull($form->attr('data-live-count'));
        self::assertSame('/_search/count?', $form->attr('data-live-count-url'));
        self::assertNotNull($form->attr('data-overlay-form'));
        self::assertCount(0, $crawler->filter('#filter-event-form button, #filter-event-form input[type="submit"]'));

        $submit = $crawler->filter('#filter-event .overlay__footer button[type="submit"]');
        self::assertSame('filter-event-form', $submit->attr('form'));
        self::assertNotNull($submit->attr('data-live-count-label'));
        self::assertSame('Keine Resultate', $submit->attr('data-count-zero'));
        self::assertSame('1 Resultat anzeigen', $submit->attr('data-count-one'));
        self::assertSame('%n Resultate anzeigen', $submit->attr('data-count-other'));
        self::assertSame('Filter löschen', $crawler->filter('#filter-event a[data-overlay-clear]')->text());
        self::assertCount(0, $crawler->filter('form form'));
    }

    public function test_the_hidden_state_carries_the_other_slots_but_not_the_event(): void
    {
        $this->eventVenue('Saal Seminar', 'seminar');

        $crawler = $this->get('/eventlokale?canton=zh&guests=80&event[]=seminar&catering[]=buffet&setting[]=lake&q=Saal&rooms=2&page=1');

        self::assertSame(
            ['canton=zh', 'guests=80', 'q=Saal', 'setting[]=lake', 'catering[]=buffet', 'rooms=2'],
            $this->hiddenState($crawler),
        );
        // And the other slots carry the event type.
        self::assertCount(1, $crawler->filter('#filter-guests-form input[type="hidden"][name="event[]"][value="seminar"]'));
        self::assertCount(1, $crawler->filter('#filter-more-form input[type="hidden"][name="event[]"][value="seminar"]'));
        self::assertCount(1, $crawler->filter('#filter-region-form input[type="hidden"][name="event[]"][value="seminar"]'));
    }

    public function test_a_submit_applies_the_ticked_types(): void
    {
        $this->eventVenue('Saal Seminar', 'seminar');
        $this->eventVenue('Saal Hochzeit', 'wedding');
        $this->venue('Saal Ohne');

        $form = $this->get('/eventlokale?guests=50&event[]=seminar')->filter('#filter-event-form')->form();
        $this->tick($form, 'wedding');
        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        parse_str((string) parse_url((string) $this->client->getRequest()->getUri(), \PHP_URL_QUERY), $query);
        self::assertEqualsCanonicalizing(['guests' => '50', 'event' => ['seminar', 'wedding']], $query);
        self::assertSame(['Saal Hochzeit', 'Saal Seminar'], $this->sortedNames($crawler));
    }

    public function test_a_canton_landing_page_keeps_its_path(): void
    {
        $this->withEvent($this->inCanton(Canton::ZH, 5), 'seminar');
        $this->withEvent($this->inCanton(Canton::ZH, 1), 'wedding');

        $crawler = $this->get('/eventlokale/zuerich?event[]=seminar');

        self::assertCount(5, $crawler->filter('.card'));
        $form = $crawler->filter('#filter-event-form');
        self::assertSame('/eventlokale/zuerich', $form->attr('action'));
        self::assertSame('/_search/count?canton=zh&', $form->attr('data-live-count-url'));
        self::assertSame([], $this->hiddenState($crawler));
        self::assertSame('/eventlokale/zuerich', $crawler->filter('#filter-event a[data-overlay-clear]')->attr('href'));

        $form = $form->form();
        $this->tick($form, 'wedding');
        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        $uri = (string) $this->client->getRequest()->getUri();
        parse_str((string) parse_url($uri, \PHP_URL_QUERY), $query);
        self::assertSame('/eventlokale/zuerich', parse_url($uri, \PHP_URL_PATH));
        self::assertEqualsCanonicalizing(['event' => ['seminar', 'wedding']], $query);
        self::assertCount(6, $crawler->filter('.card'));
    }

    public function test_the_count_endpoint_counts_event_types(): void
    {
        $this->eventVenue('Saal Seminar', 'seminar');
        $this->eventVenue('Saal Hochzeit', 'wedding');
        $this->eventVenue('Saal Beides', 'seminar', 'wedding');
        $this->venue('Saal Ohne');

        self::assertSame(2, $this->liveCount('event[]=seminar'));
        self::assertSame(3, $this->liveCount('event[]=seminar&event[]=wedding'));
        self::assertSame(0, $this->liveCount('event[]=birthday'));
        self::assertSame(4, $this->liveCount('event[]=nonsense'));

        $query = 'event[]=wedding&event[]=apero';
        self::assertSame($this->get('/eventlokale?'.$query)->filter('.card')->count(), $this->liveCount($query));
    }

    public function test_the_filtered_page_stays_public(): void
    {
        $this->eventVenue('Saal Seminar', 'seminar');

        $this->get('/eventlokale?event[]=seminar');

        $response = $this->client->getResponse();
        self::assertTrue($response->headers->getCacheControlDirective('public'));
        self::assertSame([], $response->headers->getCookies());
        self::assertNotContains('Cookie', $response->getVary());
    }

    private function get(string $uri): Crawler
    {
        $crawler = $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful($uri);

        return $crawler;
    }

    private function liveCount(string $query): int
    {
        $this->client->request('GET', '/_search/count?'.$query);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsInt($body['count']);

        return $body['count'];
    }

    private function tick(Form $form, string $code): void
    {
        $fields = $form->get('event');
        self::assertIsArray($fields);
        foreach ($fields as $field) {
            if ($field instanceof ChoiceFormField && \in_array($code, $field->availableOptionValues(), true)) {
                $field->tick();

                return;
            }
        }
        self::fail(\sprintf('No checkbox for "%s".', $code));
    }

    /**
     * @return list<string>
     */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('.card__link')->each(static fn (Crawler $link): string => $link->text());
    }

    /**
     * The card names sorted, because the listing's daily order seed is not the point here.
     *
     * @return list<string>
     */
    private function sortedNames(Crawler $crawler): array
    {
        $names = $this->names($crawler);
        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function chips(Crawler $crawler): array
    {
        return $crawler->filter('.filter-bar .chip')->each(static fn (Crawler $chip): string => $chip->text());
    }

    /**
     * @return list<string>
     */
    private function values(Crawler $crawler): array
    {
        return $crawler->filter('#filter-event-form input[name="event[]"]')->each(static fn (Crawler $input): string => (string) $input->attr('value'));
    }

    /**
     * @return list<string>
     */
    private function hiddenState(Crawler $crawler): array
    {
        return $crawler->filter('#filter-event-form input[type="hidden"]')->each(
            static fn (Crawler $input): string => $input->attr('name').'='.$input->attr('value'),
        );
    }

    private function venue(string $name): Venue
    {
        $venue = VenueFactory::createOne(['name' => $name]);
        VenueTranslationFactory::createOne(['venue' => $venue]);

        return $venue;
    }

    private function eventVenue(string $name, string ...$codes): Venue
    {
        $venue = $this->venue($name);
        $this->withEvent([$venue], ...$codes);

        return $venue;
    }

    /**
     * @param list<Venue> $venues
     *
     * @return list<Venue>
     */
    private function withEvent(array $venues, string ...$codes): array
    {
        foreach ($venues as $venue) {
            foreach ($codes as $code) {
                VenueTermFactory::new()->term(TermGroup::SuitableFor, $code)->create(['venue' => $venue]);
            }
        }

        return $venues;
    }
}
