<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Factory\VenueFactory;
use App\Factory\VenueReviewFactory;
use App\Tests\Support\Admin\AdminSession;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueReview;
use App\Venues\ValueObject\ReviewerRole;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Bewertungen tab (#25, CMS-8, Step 8.4, 8.6): the list, add, edit with the row token, remove; score,
 * count and highlights stay in the editor (D17).
 */
final class VenueReviewsPageTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_the_list_shows_the_reviews_newest_first_and_links_to_the_rating_card(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        self::review($venue, 'Marco', '2025-01-01', str_repeat('Alles hat geklappt. ', 10));
        $newest = self::review($venue, 'Loreana', '2025-04-01', 'Ein wunderbarer Tag am See.');

        $crawler = $this->client->request('GET', self::url($venue));

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('#venue-reviews tbody tr');
        self::assertSame(['Loreana', 'Besucherin', 'April 2025', 'Ein wunderbarer Tag am See.'], $rows->first()->filter('td')->slice(0, 4)->each(static fn (Crawler $cell): string => $cell->text()));
        self::assertSame(self::url($venue).'/'.$newest->id(), $rows->first()->filter('td a')->attr('href'));
        self::assertStringEndsWith('…', $rows->eq(1)->filter('td')->eq(3)->text());
        self::assertSame('review-remove-'.$newest->id(), $crawler->filter('[aria-label="Löschen: Loreana"]')->attr('commandfor'));
        self::assertSame('/admin/locations/'.$venue->number().'#bewertung', $crawler->filter('#venue-reviews-rating')->ancestors()->filter('section')->filter('a')->attr('href'));
    }

    public function test_an_empty_list_says_so(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', self::url(self::venue()));

        self::assertSelectorTextSame('.empty__title', 'Noch keine Bewertungen');
    }

    public function test_a_review_is_added(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $crawler = $this->client->request('GET', self::url($venue).'/neu');
        self::assertSelectorTextSame('nav.tabs--record a[aria-current="page"]', 'Bewertungen');
        self::assertSame('month', $crawler->filter('#review_month')->attr('type'));

        $this->client->submit($crawler->filter('form[name="review"]')->form(), [
            'review[firstName]' => ' Lea ',
            'review[role]' => 'organiser_f',
            'review[month]' => '2026-06',
            'review[text]' => 'Ein schöner Tag.',
        ]);

        self::assertResponseRedirects(self::url($venue));
        self::assertSame('Bewertung von Lea hinzugefügt.', $this->client->followRedirect()->filter('.toast')->text());
        $row = $this->connection->fetchAssociative('SELECT locale, first_name, role, reviewed_month, text FROM venue_reviews WHERE venue_id = ?', [$venue->id()]);
        self::assertSame(['locale' => 'de', 'first_name' => 'Lea', 'role' => 'organiser_f', 'reviewed_month' => '2026-06-01', 'text' => 'Ein schöner Tag.'], $row);
        self::assertSame(1, $this->auditRows($venue));
    }

    public function test_an_invalid_month_is_a_422_with_the_values_kept(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $crawler = $this->client->request('GET', self::url($venue).'/neu');

        $crawler = $this->client->submit($crawler->filter('form[name="review"]')->form(), [
            'review[firstName]' => 'Lea',
            'review[role]' => 'organiser_f',
            'review[month]' => '2026-13',
            'review[text]' => 'Ein schöner Tag.',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Bitte einen Monat wie «2026-06» eingeben.', $crawler->filter('#review_month_error')->text());
        self::assertSame(['Lea', '2026-13'], [$crawler->filter('#review_firstName')->attr('value'), $crawler->filter('#review_month')->attr('value')]);
        self::assertSame(0, $this->reviewCount($venue));
    }

    public function test_a_review_is_edited_with_the_row_token(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $review = self::review($venue, 'Marco', '2025-01-01', 'Alles hat geklappt.');
        $crawler = $this->client->request('GET', self::url($venue).'/'.$review->id());
        self::assertSame('Marco', $crawler->filter('#review_firstName')->attr('value'));
        self::assertSame('2025-01', $crawler->filter('#review_month')->attr('value'));

        $this->client->submit($crawler->filter('form[name="review"]')->form(), ['review[text]' => 'Alles hat bestens geklappt.', 'review[month]' => '2025-02']);

        self::assertResponseRedirects(self::url($venue));
        self::assertSame('Bewertung von Marco gespeichert.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame(['Alles hat bestens geklappt.', '2025-02-01'], array_values((array) $this->connection->fetchAssociative('SELECT text, reviewed_month FROM venue_reviews WHERE id = ?', [$review->id()])));
    }

    public function test_an_unchanged_save_dispatches_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $review = self::review($venue, 'Marco', '2025-01-01', 'Alles hat geklappt.');
        $crawler = $this->client->request('GET', self::url($venue).'/'.$review->id());

        $this->client->submit($crawler->filter('form[name="review"]')->form());

        self::assertResponseRedirects(self::url($venue));
        self::assertSame('Keine Änderungen an der Bewertung von Marco.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame(0, $this->auditRows($venue));
    }

    public function test_a_stale_save_is_a_409_with_the_input_kept(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $review = self::review($venue, 'Marco', '2025-01-01', 'Alles hat geklappt.');
        $crawler = $this->client->request('GET', self::url($venue).'/'.$review->id());
        $this->connection->executeStatement("UPDATE venue_reviews SET updated_at = updated_at + INTERVAL '1 minute' WHERE id = ?", [$review->id()]);

        $crawler = $this->client->submit($crawler->filter('form[name="review"]')->form(), ['review[text]' => 'Neuer Text.']);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('inzwischen geändert', $crawler->filter('.form-error')->text());
        self::assertSame('Neuer Text.', $crawler->filter('#review_text')->text());
        self::assertSame(self::url($venue).'/'.$review->id(), $crawler->filter('.venue-card__stale a')->attr('href'));
        self::assertSame('Alles hat geklappt.', $this->connection->fetchOne('SELECT text FROM venue_reviews WHERE id = ?', [$review->id()]));
    }

    public function test_remove_deletes_the_review(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $review = self::review($venue, 'Marco', '2025-01-01', 'Alles hat geklappt.');
        $crawler = $this->client->request('GET', self::url($venue));

        $this->client->submit($crawler->filter('#review-remove-'.$review->id().' form[method="post"]')->form());

        self::assertResponseRedirects(self::url($venue));
        self::assertSame('Bewertung von Marco gelöscht.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame(0, $this->reviewCount($venue));
    }

    public function test_a_missing_csrf_token_changes_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $review = self::review($venue, 'Marco', '2025-01-01', 'Alles hat geklappt.');

        $this->client->request('POST', self::url($venue).'/'.$review->id().'/loeschen');

        self::assertResponseRedirects(self::url($venue));
        self::assertStringContainsString('Das hat nicht geklappt', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame(1, $this->reviewCount($venue));

        $this->client->request('POST', self::url($venue).'/neu', ['review' => ['locale' => 'de', 'firstName' => 'Lea', 'role' => 'visitor_f', 'month' => '2026-06', 'text' => 'Gut.']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->reviewCount($venue));
    }

    public function test_a_wrong_csrf_token_refuses_a_save(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $review = self::review($venue, 'Marco', '2025-01-01', 'Alles hat geklappt.');
        $crawler = $this->client->request('GET', self::url($venue).'/'.$review->id());

        $this->client->submit($crawler->filter('form[name="review"]')->form(), ['review[text]' => 'Neuer Text.', 'review[_token]' => 'falsch']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Alles hat geklappt.', $this->connection->fetchOne('SELECT text FROM venue_reviews WHERE id = ?', [$review->id()]));
        self::assertSame(0, $this->auditRows($venue));
    }

    public function test_unknown_and_foreign_reviews_are_404(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $foreign = self::review(self::venue(), 'Fremd', '2025-01-01', 'Anderswo.');

        $this->client->request('GET', self::url($venue).'/'.$foreign->id());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', self::url($venue).'/'.$foreign->id().'/loeschen');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/admin/locations/99999999/bewertungen/neu');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_an_anonymous_visitor_is_sent_to_the_login(): void
    {
        $this->client->request('GET', '/admin/locations/1/bewertungen/neu');
        self::assertResponseRedirects('/admin/login');
    }

    private static function venue(): Venue
    {
        return VenueFactory::new()->withoutLocation()->create();
    }

    private static function url(Venue $venue): string
    {
        return '/admin/locations/'.$venue->number().'/bewertungen';
    }

    private static function review(Venue $venue, string $firstName, string $month, string $text): VenueReview
    {
        return VenueReviewFactory::createOne(['venue' => $venue, 'firstName' => $firstName, 'role' => ReviewerRole::VisitorF, 'reviewedMonth' => new \DateTimeImmutable($month), 'text' => $text, 'locale' => 'de']);
    }

    private function reviewCount(Venue $venue): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_reviews WHERE venue_id = ?', [$venue->id()]);
    }

    private function auditRows(Venue $venue): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $venue->number()]);
    }
}
