<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenueDocumentFactory;
use App\Factory\VenueDocumentTranslationFactory;
use App\Factory\VenueFactory;
use App\Tests\Application\Cms\SendsVenueMediaRequests;
use App\Tests\Support\Content\ContentFolder;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Tests\Support\Media\TestImages;
use App\Tests\Support\Portal\PortalSession;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueDocument;
use App\Venues\ValueObject\KnownTiers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * F11: a manager keeps the PDFs of their own location on the Dokumente tab, staff's pages (`VenueDocumentPages`) in the
 * location layout: upload, titles, order and removal behind the voter, the plan's PDF limit (F12), the shared upload
 * budget, and a purge of the venue's page on every write.
 */
final class LocationDocumentsTest extends WebTestCase
{
    use Factories;
    use PortalSession {
        tearDown as private endPortalSession;
    }
    use SendsVenueMediaRequests;

    protected function tearDown(): void
    {
        $this->removeVenueMedia();
        $this->endPortalSession();
    }

    public function test_an_upload_adds_the_pdf_and_purges_the_venue(): void
    {
        $venue = $this->managedVenue();
        $crawler = $this->client->request('GET', self::url($venue));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.empty__title', 'Noch keine Dokumente');
        self::assertSelectorTextContains('.tabs--record [aria-current="page"]', 'Dokumente');
        self::assertSelectorTextContains('aside', 'PDFs 0 von 1');

        $form = $crawler->filter('form[name="document_upload"]')->form();
        self::assertSame('http://localhost'.self::url($venue), $form->getUri());
        self::attach($form, $this->pdf('Fiktive Preisliste'));
        $this->client->submit($form, ['document_upload[titles][de]' => 'Preisliste 2027']);

        self::assertResponseRedirects(self::url($venue));
        self::assertSame([$venue->number()], array_values(array_unique($this->purger()->venues)));
        $crawler = $this->client->followRedirect();
        self::assertSame('«Preisliste 2027» hochgeladen.', $crawler->filter('.toast')->text());
        self::assertSame('Preisliste 2027', $crawler->filter('.sortable__label a')->text());
        self::assertStringStartsWith(self::url($venue).'/', (string) $crawler->filter('.sortable__label a')->attr('href'));
        self::assertSame(['portal_user'], $this->connection->fetchFirstColumn("SELECT actor_type FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $venue->number()]));
    }

    public function test_a_second_pdf_on_free_is_refused_on_the_file_with_the_plan_named(): void
    {
        $venue = $this->managedVenue();
        self::document($venue, 1, 'Erste Liste');

        $form = $this->client->request('GET', self::url($venue))->filter('form[name="document_upload"]')->form();
        self::attach($form, $this->pdf('Zweite Liste'));
        $crawler = $this->client->submit($form, ['document_upload[titles][de]' => 'Zweite Liste']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(
            'Mit dem Plan Free ist höchstens 1 PDF möglich. Entferne zuerst ein PDF; mehr gibt es mit einem grösseren Plan.',
            $crawler->filter('.field__error')->first()->text(),
        );
        self::assertSelectorExists('aside form[action="/konto/locations/'.$venue->number().'/pro-anfragen"]');
        self::assertSame(1, $this->documentCount($venue));
    }

    public function test_an_upload_past_the_budget_is_refused_without_a_write(): void
    {
        $venue = $this->managedVenue(KnownTiers::PRO);
        $limiter = self::getContainer()->get('limiter.portal_upload');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiter);
        $managerId = (string) $this->connection->fetchOne('SELECT portal_user_id FROM portal_venue_access WHERE venue_id = ?', [$venue->id()]);
        $limiter->create($managerId)->consume(60);

        $form = $this->client->request('GET', self::url($venue))->filter('form[name="document_upload"]')->form();
        self::attach($form, $this->pdf('Liste'));
        $crawler = $this->client->submit($form, ['document_upload[titles][de]' => 'Liste']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('sehr viele Dateien hochgeladen', $crawler->filter('.field__error')->text());
        self::assertSame(0, $this->documentCount($venue));
        $limiter->create($managerId)->reset();
    }

    public function test_titles_order_and_removal_work_and_stay_on_the_tab(): void
    {
        $venue = $this->managedVenue(KnownTiers::PRO);
        $first = self::document($venue, 1, 'Preisliste');
        $second = self::document($venue, 2, 'Menü');

        $crawler = $this->client->request('GET', self::url($venue).'/'.$first->id());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tabs--record [aria-current="page"]', 'Dokumente');
        $this->client->submit($crawler->filter('form[name="document_title"]')->form(), ['document_title[titles][de]' => 'Preisliste 2027']);
        self::assertResponseRedirects(self::url($venue));
        self::assertSame('Preisliste 2027', $this->connection->fetchOne('SELECT title FROM venue_document_translations WHERE venue_document_id = ?', [$first->id()]));

        $crawler = $this->client->request('GET', self::url($venue));
        $this->client->submit($crawler->filter(\sprintf('form[action="%s/%s/nach-unten"]', self::url($venue), $first->id()))->form());
        self::assertResponseRedirects(self::url($venue).'#venue-documents');
        self::assertSame([$second->id(), $first->id()], $this->connection->fetchFirstColumn('SELECT id FROM venue_documents WHERE venue_id = ? ORDER BY position', [$venue->id()]));

        $crawler = $this->client->request('GET', self::url($venue));
        $this->client->submit($crawler->filter('#document-remove-'.$second->id())->filter('form[method="post"]')->form());
        self::assertResponseRedirects(self::url($venue));
        self::assertSame(1, $this->documentCount($venue));
        self::assertSame([$venue->number()], array_values(array_unique($this->purger()->venues)));
    }

    public function test_a_missing_token_changes_nothing(): void
    {
        $venue = $this->managedVenue(KnownTiers::PRO);
        $document = self::document($venue, 1, 'Preisliste');

        $this->client->request('POST', self::url($venue).'/'.$document->id().'/loeschen', ['_token' => 'falsch']);

        self::assertResponseRedirects(self::url($venue));
        self::assertSame(1, $this->documentCount($venue));
    }

    public function test_another_managers_location_and_its_documents_are_403(): void
    {
        $this->logInAsManager();
        $other = VenueFactory::new()->withoutLocation()->create();
        PortalVenueAccessFactory::new()->active()->create(['venue' => $other]);
        $document = self::document($other, 1, 'Preisliste');

        $this->client->request('GET', self::url($other));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->client->request('GET', self::url($other).'/'.$document->id());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->client->request('POST', self::url($other).'/'.$document->id().'/loeschen');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(1, $this->documentCount($other));
    }

    public function test_a_document_of_another_location_is_404_on_the_own_tab(): void
    {
        $venue = $this->managedVenue();
        $foreign = self::document(VenueFactory::new()->withoutLocation()->create(), 1, 'Fremd');

        $this->client->request('GET', self::url($venue).'/'.$foreign->id());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function managedVenue(string $tier = KnownTiers::FREE): Venue
    {
        $venue = VenueFactory::new()->withoutLocation()->create(['tier' => $tier]);
        PortalVenueAccessFactory::new()->active()->create(['user' => $this->logInAsManager(), 'venue' => $venue]);

        return $venue;
    }

    private static function url(Venue $venue): string
    {
        return '/konto/locations/'.$venue->number().'/dokumente';
    }

    private static function document(Venue $venue, int $position, string $title): VenueDocument
    {
        $document = VenueDocumentFactory::createOne(['venue' => $venue, 'position' => $position]);
        VenueDocumentTranslationFactory::createOne(['document' => $document, 'title' => $title]);

        return $document;
    }

    private static function attach(Form $form, string $path): void
    {
        $field = $form['document_upload[file]'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($path);
    }

    private function pdf(string $text): string
    {
        $this->uploads ??= sys_get_temp_dir().'/portal-documents-'.bin2hex(random_bytes(4));

        return TestImages::write($this->uploads.'/'.uniqid(), 'preisliste.pdf', ContentFolder::pdf($text.' '.uniqid()));
    }

    private function documentCount(Venue $venue): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_documents WHERE venue_id = ?', [$venue->id()]);
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }
}
