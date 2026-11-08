<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Zenstruck\Foundry\Test\Factories;

/**
 * F12: event types are checked when they are written (CMS and `/konto` share the Veranstaltung card since F11, its form and its
 * `ChangeVenueEventFacts` handler), never hidden when a page is read. The refusal sits on the chip group it belongs to, and
 * a venue that already holds more than its plan allows is not locked out of saving the rest of the card.
 */
final class EventTypeLimitTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ImportsTownDataset;

    public function test_a_free_venue_cannot_grow_its_event_types_beyond_the_plan(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue(KnownTiers::FREE);
        $codes = $this->codes(4);
        $this->store($venue, \array_slice($codes, 0, 3));

        $crawler = $this->submit($venue, $codes);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            'Mit dem Plan Free sind höchstens 3 Anlassarten möglich. Wähle weniger aus; mehr gibt es mit einem grösseren Plan.',
            trim($crawler->filter('#venue_event_suitable_for_error')->text()),
            'the message sits on the chip group',
        );
        self::assertSame(\array_slice($codes, 0, 3), $this->stored($venue), 'nothing was written');
    }

    public function test_a_free_venue_within_the_plan_saves(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue(KnownTiers::FREE);
        $codes = $this->codes(3);

        $this->submit($venue, $codes);

        self::assertResponseRedirects();
        self::assertSame($codes, $this->stored($venue));
    }

    public function test_a_venue_that_already_holds_more_keeps_its_list_may_shorten_it_and_cannot_grow_it(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue(KnownTiers::FREE);
        $codes = $this->codes(6);
        $this->store($venue, \array_slice($codes, 0, 5));

        $this->submit($venue, \array_slice($codes, 0, 5));
        self::assertResponseRedirects();
        self::assertCount(5, $this->stored($venue), 'the same five save: the rest of the card is not locked');

        $this->submit($venue, \array_slice($codes, 0, 4));
        self::assertResponseRedirects();
        self::assertCount(4, $this->stored($venue), 'shortening is allowed step by step');

        $this->submit($venue, $codes);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(4, $this->stored($venue), 'growing past the plan is not');
    }

    public function test_a_pro_venue_has_no_event_type_limit(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue(KnownTiers::PRO);
        $codes = $this->codes(8);

        $this->submit($venue, $codes);

        self::assertResponseRedirects();
        self::assertSame($codes, $this->stored($venue));
    }

    private function venue(string $tier): Venue
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $venue = DetailVenues::minimal(tier: $tier);
        $em->flush();

        return $venue;
    }

    /**
     * @return list<string> the first codes of the event-type group in position order
     */
    private function codes(int $count): array
    {
        /** @var list<string> $codes */
        $codes = $this->connection->fetchFirstColumn("SELECT code FROM taxonomy_terms WHERE term_group = 'suitable_for' ORDER BY position LIMIT ".$count);
        self::assertCount($count, $codes);

        return $codes;
    }

    /**
     * @param list<string> $codes
     */
    private function store(Venue $venue, array $codes): void
    {
        foreach ($codes as $code) {
            $this->connection->executeStatement('INSERT INTO venue_terms (venue_id, term_group, term_code, is_main) VALUES (?, ?, ?, FALSE)', [$venue->id(), 'suitable_for', $code]);
        }
    }

    /**
     * @return list<string> the venue's stored event types in position order
     */
    private function stored(Venue $venue): array
    {
        /** @var list<string> $codes */
        $codes = $this->connection->fetchFirstColumn(
            "SELECT vt.term_code FROM venue_terms vt JOIN taxonomy_terms tt ON tt.term_group = vt.term_group AND tt.code = vt.term_code WHERE vt.venue_id = ? AND vt.term_group = 'suitable_for' ORDER BY tt.position",
            [$venue->id()],
        );

        return $codes;
    }

    /**
     * Opens the Veranstaltung card and ticks exactly these event types, as a browser would.
     *
     * @param list<string> $codes
     */
    private function submit(Venue $venue, array $codes): Crawler
    {
        $crawler = $this->client->request('GET', '/admin/locations/'.$venue->number());
        $form = $crawler->filter('form[name="venue_event"]')->form();
        $boxes = $form['venue_event[suitable_for]'];
        self::assertIsArray($boxes);
        foreach ($boxes as $box) {
            self::assertInstanceOf(ChoiceFormField::class, $box);
            if (\in_array($box->availableOptionValues()[0], $codes, true)) {
                $box->tick();
            } else {
                $box->untick();
            }
        }

        return $this->client->submit($form);
    }
}
