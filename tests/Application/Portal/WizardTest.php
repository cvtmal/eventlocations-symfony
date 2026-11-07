<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalVenueAccessFactory;
use App\Factory\SubmissionFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Portal\Entity\PortalUser;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Portal\PortalSession;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\VenueStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * The wizard's card steps (#45, POR-11): the step derived from what a location lacks, the fixed order after a save, and
 * the contact step's required address. Access is Step 7.1's walker.
 */
final class WizardTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use PortalSession;

    private const string SUMMARY = 'Ein Haus am See.';
    private const string ADDRESS = 'info@seehaus.example';
    private const array EVERYTHING = ['summary', 'main_building', 'photo', 'inquiry_email'];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function gaps(): iterable
    {
        yield 'summary' => ['summary', 'texte'];
        yield 'main building' => ['main_building', 'ausstattung'];
        yield 'photo' => ['photo', 'fotos'];
        yield 'inquiry address' => ['inquiry_email', 'kontakt'];
    }

    #[DataProvider('gaps')]
    public function test_bare_url_resumes_at_the_first_lacking_step(string $gap, string $step): void
    {
        $venue = $this->location($this->logInAsManager(), array_values(array_diff(self::EVERYTHING, [$gap])));

        $this->client->request('GET', $this->url($venue));

        self::assertResponseRedirects($this->url($venue, $step), Response::HTTP_FOUND);
    }

    public function test_with_nothing_missing_it_lands_on_the_check(): void
    {
        $venue = $this->location($this->logInAsManager());

        $this->client->request('GET', $this->url($venue));

        self::assertResponseRedirects($this->url($venue, 'pruefen'));
    }

    public function test_a_filled_step_is_not_jumped_over_after_a_save(): void
    {
        $venue = $this->location($this->logInAsManager());

        $this->save($venue, 'texte', 'venue_texts', ['venue_texts[texts][de][summary]' => 'Ein neuer Kurztext.']);
        self::assertResponseRedirects($this->url($venue, 'ausstattung'), Response::HTTP_SEE_OTHER);

        $this->save($venue, 'ausstattung', 'venue_terms');
        self::assertResponseRedirects($this->url($venue, 'veranstaltung'), Response::HTTP_SEE_OTHER);

        $this->save($venue, 'veranstaltung', 'venue_event');
        self::assertResponseRedirects($this->url($venue, 'fotos'), Response::HTTP_SEE_OTHER);
    }

    public function test_terms_and_event_are_two_pages_at_one_position(): void
    {
        $venue = $this->location($this->logInAsManager());

        foreach (['ausstattung' => 'Ausstattung', 'veranstaltung' => 'Veranstaltung'] as $step => $heading) {
            $crawler = $this->client->request('GET', $this->url($venue, $step));

            self::assertResponseIsSuccessful();
            self::assertSame($heading, $crawler->filter('h1')->text());
            self::assertSame('Schritt 3 von 6', $crawler->filter('.progress__label')->text());
            self::assertSame('3', $crawler->filter('progress')->attr('value'));
            self::assertSame('6', $crawler->filter('progress')->attr('max'));
            self::assertSame('Weiter', $crawler->filter('.venue-card button[type="submit"]')->last()->text());
        }
    }

    public function test_an_unchanged_form_moves_on_and_dispatches_nothing(): void
    {
        $venue = $this->location($this->logInAsManager());

        $this->save($venue, 'texte', 'venue_texts');

        self::assertResponseRedirects($this->url($venue, 'ausstattung'), Response::HTTP_SEE_OTHER);
        self::assertSame(0, $this->auditRows($venue));
        self::assertNull($this->connection->fetchOne('SELECT cms_edited_at FROM venues WHERE id = ?', [$venue->id()]));
    }

    public function test_a_stale_token_is_409_and_keeps_the_input(): void
    {
        $venue = $this->location($this->logInAsManager());
        $form = $this->form($venue, 'texte', 'venue_texts');
        $this->connection->executeStatement("UPDATE venues SET updated_at = updated_at + INTERVAL '1 hour' WHERE id = ?", [$venue->id()]);

        $crawler = $this->client->submit($form, ['venue_texts[texts][de][summary]' => 'Ein neuer Kurztext.']);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('Ein neuer Kurztext.', $crawler->filter('#venue_texts_texts_de_summary')->text());
        self::assertSame('/konto/locations/'.$venue->number().'#texte', $crawler->filter('#texte .venue-card__stale a')->attr('href'));
        self::assertSame(self::SUMMARY, $this->connection->fetchOne("SELECT summary FROM venue_translations WHERE venue_id = ? AND locale = 'de'", [$venue->id()]));
    }

    public function test_kontakt_is_prefilled_with_the_account_email(): void
    {
        $manager = $this->logInAsManager();
        $venue = $this->location($manager, ['summary', 'main_building', 'photo']);

        $crawler = $this->client->request('GET', $this->url($venue, 'kontakt'));

        self::assertResponseIsSuccessful();
        self::assertSame($manager->email(), $crawler->filter('#venue_contact_inquiryEmail')->attr('value'));
    }

    public function test_kontakt_cleared_is_refused_with_422(): void
    {
        $venue = $this->location($this->logInAsManager(), ['summary', 'main_building', 'photo']);

        $crawler = $this->save($venue, 'kontakt', 'venue_contact', ['venue_contact[inquiryEmail]' => '']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('Bitte gib eine E-Mail-Adresse für Anfragen an.', $crawler->filter('#kontakt')->text());
        self::assertNull($this->inquiryEmail($venue));
    }

    public function test_a_kept_prefill_is_stored(): void
    {
        $manager = $this->logInAsManager();
        $venue = $this->location($manager, ['summary', 'main_building', 'photo']);

        $this->save($venue, 'kontakt', 'venue_contact');

        self::assertResponseRedirects($this->url($venue, 'pruefen'), Response::HTTP_SEE_OTHER);
        self::assertSame($manager->email(), $this->inquiryEmail($venue));
    }

    public function test_an_existing_address_is_not_rewritten(): void
    {
        $venue = $this->location($this->logInAsManager());

        $this->save($venue, 'kontakt', 'venue_contact');

        self::assertResponseRedirects($this->url($venue, 'pruefen'), Response::HTTP_SEE_OTHER);
        self::assertSame(self::ADDRESS, $this->inquiryEmail($venue));
        self::assertSame(0, $this->auditRows($venue));
    }

    public function test_a_published_location_redirects_to_the_location_page(): void
    {
        $venue = $this->location($this->logInAsManager());
        $this->connection->executeStatement("UPDATE venues SET status = 'published' WHERE id = ?", [$venue->id()]);

        foreach ([$this->url($venue), $this->url($venue, 'texte'), $this->url($venue, 'pruefen')] as $url) {
            $this->client->request('GET', $url);

            self::assertResponseRedirects('/konto/locations/'.$venue->number());
        }
    }

    public function test_an_unknown_step_is_404(): void
    {
        $venue = $this->location($this->logInAsManager());

        $this->client->request('GET', $this->url($venue, 'bezahlen'));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * An unpublished location of `$manager` with a draft, holding only the given gap codes.
     *
     * @param list<string> $has
     */
    private function location(PortalUser $manager, array $has = self::EVERYTHING): Venue
    {
        $venue = VenueFactory::createOne(['status' => VenueStatus::Unpublished]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'summary' => \in_array('summary', $has, true) ? self::SUMMARY : '']);
        if (\in_array('main_building', $has, true)) {
            VenueTermFactory::new()->term(TermGroup::Building, 'castle')->main()->create(['venue' => $venue]);
        }
        if (\in_array('photo', $has, true)) {
            VenuePhotoFactory::createOne(['venue' => $venue]);
        }
        if (\in_array('inquiry_email', $has, true)) {
            $this->connection->executeStatement('UPDATE venues SET inquiry_email = ? WHERE id = ?', [self::ADDRESS, $venue->id()]);
        }
        SubmissionFactory::createOne(['venue' => $venue]);
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);

        return $venue;
    }

    /**
     * @param array<string, string> $values
     */
    private function save(Venue $venue, string $step, string $formName, array $values = []): Crawler
    {
        return $this->client->submit($this->form($venue, $step, $formName), $values);
    }

    private function form(Venue $venue, string $step, string $formName): Form
    {
        return $this->client->request('GET', $this->url($venue, $step))->filter('form[name="'.$formName.'"]')->form();
    }

    private function url(Venue $venue, ?string $step = null): string
    {
        return '/konto/locations/'.$venue->number().'/einrichten'.(null === $step ? '' : '/'.$step);
    }

    private function inquiryEmail(Venue $venue): mixed
    {
        return $this->connection->fetchOne('SELECT inquiry_email FROM venues WHERE id = ?', [$venue->id()]);
    }

    private function auditRows(Venue $venue): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $venue->number()]);
    }
}
