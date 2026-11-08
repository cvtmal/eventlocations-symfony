<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Factory\VenueDocumentFactory;
use App\Factory\VenueDocumentTranslationFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Content\ContentFolder;
use App\Tests\Support\Media\TestImages;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueDocument;
use App\Venues\ValueObject\KnownTiers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Dokumente tab (#25, CMS-7, Step 8.3, 8.6): upload, titles with the row token, move up and down,
 * remove, each POST → redirect → GET with a flash; 422 and 409 keep the input.
 */
final class VenueDocumentsPageTest extends WebTestCase
{
    use AdminSession {
        tearDown as private endAdminSession;
    }
    use Factories;
    use SendsVenueMediaRequests;

    protected function tearDown(): void
    {
        $this->removeVenueMedia();
        $this->endAdminSession();
    }

    public function test_an_upload_adds_the_pdf_with_its_title(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $crawler = $this->client->request('GET', self::url($venue));
        self::assertSelectorTextSame('.empty__title', 'Noch keine Dokumente');

        $form = $crawler->filter('form[name="document_upload"]')->form();
        self::attach($form, $this->pdf('Fiktive Preisliste'));
        $this->client->submit($form, ['document_upload[titles][de]' => '  Preisliste 2027 ']);

        self::assertResponseRedirects(self::url($venue));
        $crawler = $this->client->followRedirect();
        self::assertSame('«Preisliste 2027» hochgeladen.', $crawler->filter('.toast')->text());
        $row = $this->connection->fetchAssociative('SELECT d.id, d.path, t.title FROM venue_documents d JOIN venue_document_translations t ON t.venue_document_id = d.id WHERE d.venue_id = ?', [$venue->id()]);
        self::assertIsArray($row);
        self::assertSame('Preisliste 2027', $row['title']);
        self::assertStringStartsWith('cms/'.$venue->number().'/', (string) $row['path']);
        $link = $crawler->filter('.sortable__label a');
        self::assertSame('Preisliste 2027', $link->text());
        self::assertSame(self::url($venue).'/'.$row['id'], $link->attr('href'));
        self::assertSame('/media/'.$venue->number().'/'.$row['id'].'.pdf', $crawler->filter('.sortable__actions a')->attr('href'));
        self::assertNotNull($this->connection->fetchOne('SELECT cms_edited_at FROM venues WHERE id = ?', [$venue->id()]));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function refusedUploads(): iterable
    {
        yield 'a JPEG' => ['jpeg', 'Preisliste', '#document_upload_file', 'Bitte ein PDF hochladen.'];
        yield 'more than 15 MB' => ['large', 'Preisliste', '#document_upload_file', 'Das PDF ist zu gross, höchstens 15 MB.'];
        yield 'no title' => ['pdf', '', '#document_upload_titles_de', 'Bitte einen Titel eingeben.'];
        yield 'a title of 201 characters' => ['pdf', str_repeat('x', 201), '#document_upload_titles_de', 'höchstens 200'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedUploads')]
    public function test_an_invalid_upload_is_a_422_with_the_title_kept(string $kind, string $title, string $field, string $message): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $form = $this->client->request('GET', self::url($venue))->filter('form[name="document_upload"]')->form();
        self::attach($form, match ($kind) {
            'jpeg' => $this->file('foto.pdf', TestImages::uniformJpeg(40, 30, 1, 2, 3)),
            'large' => $this->file('gross.pdf', ContentFolder::pdf('Gross').str_repeat(' ', 15_000_001)),
            default => $this->pdf('Fiktiv'),
        });

        $crawler = $this->client->submit($form, ['document_upload[titles][de]' => $title]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, self::fieldError($crawler, $field));
        self::assertSame($title, $crawler->filter('#document_upload_titles_de')->attr('value') ?? '');
        self::assertSame(0, $this->documentCount($venue));
    }

    public function test_the_same_pdf_twice_is_refused_on_the_file(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $bytes = ContentFolder::pdf('Dieselbe Preisliste');
        foreach (['Preisliste', 'Nochmals'] as $title) {
            $form = $this->client->request('GET', self::url($venue))->filter('form[name="document_upload"]')->form();
            self::attach($form, $this->file('liste.pdf', $bytes));
            $crawler = $this->client->submit($form, ['document_upload[titles][de]' => $title]);
        }

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Dieses PDF ist bereits bei den Dokumenten.', self::fieldError($crawler, '#document_upload_file'));
        self::assertSame(1, $this->documentCount($venue));
    }

    /**
     * F12: the plan decides how many PDFs a location holds. Free holds one, so the second is refused on the file
     * field with a message that names the plan; Pro takes it.
     */
    public function test_a_second_pdf_is_refused_on_free_and_taken_on_pro(): void
    {
        $this->logInAs(AdminRole::Editor);
        $free = self::venue();
        self::document($free, 1, 'Erste Liste');

        $form = $this->client->request('GET', self::url($free))->filter('form[name="document_upload"]')->form();
        self::attach($form, $this->pdf('Zweite Liste'));
        $crawler = $this->client->submit($form, ['document_upload[titles][de]' => 'Zweite Liste']);

        self::assertResponseStatusCodeSame(422);
        $message = self::fieldError($crawler, '#document_upload_file');
        self::assertStringContainsString('Gratis', $message);
        self::assertStringContainsString('1 PDF', $message);
        self::assertSame(1, $this->documentCount($free));

        $pro = self::venue(KnownTiers::PRO);
        self::document($pro, 1, 'Erste Liste');
        $form = $this->client->request('GET', self::url($pro))->filter('form[name="document_upload"]')->form();
        self::attach($form, $this->pdf('Zweite Liste'));
        $this->client->submit($form, ['document_upload[titles][de]' => 'Zweite Liste']);

        self::assertResponseRedirects(self::url($pro));
        self::assertSame(2, $this->documentCount($pro));
    }

    public function test_titles_are_saved_with_the_row_token(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $document = self::document($venue, 1, 'Preisliste');
        $crawler = $this->client->request('GET', self::url($venue).'/'.$document->id());
        self::assertSelectorTextSame('nav.tabs--record a[aria-current="page"]', 'Dokumente');
        self::assertSame('Preisliste', $crawler->filter('#document_title_titles_de')->attr('value'));

        $this->client->submit($crawler->filter('form[name="document_title"]')->form(), ['document_title[titles][de]' => 'Preisliste 2027']);

        self::assertResponseRedirects(self::url($venue));
        self::assertSame('«Preisliste 2027» gespeichert.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame('Preisliste 2027', $this->title($document));
        self::assertSame(1, $this->auditRows($venue));
    }

    public function test_an_unchanged_save_dispatches_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $document = self::document($venue, 1, 'Preisliste');
        $crawler = $this->client->request('GET', self::url($venue).'/'.$document->id());

        $this->client->submit($crawler->filter('form[name="document_title"]')->form(), ['document_title[titles][de]' => ' Preisliste ']);

        self::assertResponseRedirects(self::url($venue));
        self::assertSame('Keine Änderungen an «Preisliste».', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame(0, $this->auditRows($venue));
    }

    public function test_a_stale_save_is_a_409_with_the_input_kept(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $document = self::document($venue, 1, 'Preisliste');
        $crawler = $this->client->request('GET', self::url($venue).'/'.$document->id());
        $this->connection->executeStatement("UPDATE venue_documents SET updated_at = updated_at + INTERVAL '1 minute' WHERE id = ?", [$document->id()]);

        $crawler = $this->client->submit($crawler->filter('form[name="document_title"]')->form(), ['document_title[titles][de]' => 'Neuer Titel']);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('inzwischen geändert', $crawler->filter('.form-error')->text());
        self::assertSame('Neuer Titel', $crawler->filter('#document_title_titles_de')->attr('value'));
        self::assertSame(self::url($venue).'/'.$document->id(), $crawler->filter('.venue-card__stale a')->attr('href'));
        self::assertSame('Preisliste', $this->title($document));
    }

    public function test_an_empty_title_is_a_422(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $document = self::document($venue, 1, 'Preisliste');
        $crawler = $this->client->request('GET', self::url($venue).'/'.$document->id());

        $crawler = $this->client->submit($crawler->filter('form[name="document_title"]')->form(), ['document_title[titles][de]' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Bitte einen Titel eingeben.', self::fieldError($crawler, '#document_title_titles_de'));
    }

    public function test_the_move_buttons_swap_neighbours(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $first = self::document($venue, 1, 'Preisliste');
        $second = self::document($venue, 2, 'Menü');
        $third = self::document($venue, 3, 'Raumplan');
        $crawler = $this->client->request('GET', self::url($venue));
        self::assertSame(['Preisliste', 'Menü', 'Raumplan'], $crawler->filter('.sortable__label a')->each(static fn (Crawler $link): string => $link->text()));
        self::assertSame('true', $crawler->filter('.sortable__item')->first()->filter('[aria-label="Nach oben: Preisliste"]')->attr('aria-disabled'));

        $this->client->submit($crawler->filter(\sprintf('form[action="%s/%s/nach-unten"]', self::url($venue), $first->id()))->form());

        self::assertResponseRedirects(self::url($venue).'#venue-documents');
        $crawler = $this->client->followRedirect();
        self::assertSame('«Preisliste» ist jetzt an Position 2.', $crawler->filter('.toast')->text());
        self::assertSame([$second->id(), $first->id(), $third->id()], $this->order($venue));

        $this->client->submit($crawler->filter(\sprintf('form[action="%s/%s/nach-oben"]', self::url($venue), $third->id()))->form());
        self::assertSame([$second->id(), $third->id(), $first->id()], $this->order($venue));
    }

    public function test_a_move_past_the_end_changes_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $first = self::document($venue, 1, 'Preisliste');
        self::document($venue, 2, 'Menü');
        $token = $this->client->request('GET', self::url($venue))->filter('.sortable__moves input[name="_token"]')->attr('value');

        $this->client->request('POST', self::url($venue).'/'.$first->id().'/nach-oben', ['_token' => $token]);

        self::assertResponseRedirects(self::url($venue));
        self::assertSame(0, $this->auditRows($venue));
    }

    public function test_remove_deletes_the_document(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $document = self::document($venue, 1, 'Preisliste');
        $crawler = $this->client->request('GET', self::url($venue));
        $dialog = $crawler->filter('#document-remove-'.$document->id());
        self::assertSame('«Preisliste» wird gelöscht und verschwindet von der Website.', $dialog->filter('.modal__body')->text());
        self::assertSame('document-remove-'.$document->id(), $crawler->filter('[aria-label="Löschen: Preisliste"]')->attr('commandfor'));

        $this->client->submit($dialog->filter('form[method="post"]')->form());

        self::assertResponseRedirects(self::url($venue));
        self::assertSame('«Preisliste» gelöscht.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame(0, $this->documentCount($venue));
    }

    public function test_a_missing_csrf_token_changes_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $first = self::document($venue, 1, 'Preisliste');
        $second = self::document($venue, 2, 'Menü');

        foreach ([[$first, 'nach-unten'], [$second, 'nach-oben'], [$first, 'loeschen']] as [$document, $action]) {
            $this->client->request('POST', self::url($venue).'/'.$document->id().'/'.$action, ['_token' => 'falsch']);

            self::assertResponseRedirects(self::url($venue), null, $action);
            self::assertStringContainsString('Das hat nicht geklappt', $this->client->followRedirect()->filter('.toast')->text(), $action);
        }
        self::assertSame([$first->id(), $second->id()], $this->order($venue));
        self::assertSame(0, $this->auditRows($venue));
    }

    public function test_a_wrong_csrf_token_refuses_an_upload(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $crawler = $this->client->request('GET', self::url($venue));
        $form = $crawler->filter('form[name="document_upload"]')->form();
        self::attach($form, $this->pdf('Fiktive Preisliste'));

        $this->client->submit($form, ['document_upload[titles][de]' => 'Preisliste', 'document_upload[_token]' => 'falsch']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->documentCount($venue));
        self::assertSame(0, $this->auditRows($venue));
    }

    public function test_a_wrong_csrf_token_refuses_a_title_save(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $document = self::document($venue, 1, 'Preisliste');
        $crawler = $this->client->request('GET', self::url($venue).'/'.$document->id());

        $this->client->submit($crawler->filter('form[name="document_title"]')->form(), ['document_title[titles][de]' => 'Neu', 'document_title[_token]' => 'falsch']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Preisliste', $this->connection->fetchOne('SELECT title FROM venue_document_translations WHERE venue_document_id = ?', [$document->id()]));
        self::assertSame(0, $this->auditRows($venue));
    }

    public function test_the_upload_field_gives_the_browser_the_limit(): void
    {
        $this->logInAs(AdminRole::Editor);
        $crawler = $this->client->request('GET', self::url(self::venue()));

        $field = $crawler->filter('#document_upload_file');
        self::assertSame('15000000', $field->attr('data-max-bytes'));
        self::assertSame('Das PDF ist zu gross, höchstens 15 MB.', $field->attr('data-max-message'));
    }

    public function test_unknown_and_foreign_documents_are_404(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $foreign = self::document(self::venue(), 1, 'Fremd');

        $this->client->request('GET', self::url($venue).'/'.$foreign->id());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', self::url($venue).'/'.$foreign->id().'/loeschen');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/admin/locations/99999999/dokumente');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_an_anonymous_visitor_is_sent_to_the_login(): void
    {
        $this->client->request('GET', '/admin/locations/1/dokumente');
        self::assertResponseRedirects('/admin/login');
        $this->client->request('POST', '/admin/locations/1/dokumente');
        self::assertResponseRedirects('/admin/login');
    }

    private static function venue(string $tier = KnownTiers::FREE): Venue
    {
        return VenueFactory::new()->withoutLocation()->create(['tier' => $tier]);
    }

    private static function url(Venue $venue): string
    {
        return '/admin/locations/'.$venue->number().'/dokumente';
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

    private static function fieldError(Crawler $crawler, string $field): string
    {
        $describedBy = (string) $crawler->filter($field)->attr('aria-describedby');
        foreach (explode(' ', $describedBy) as $id) {
            $error = $crawler->filter('#'.$id.'.field__error');
            if ($error->count() > 0) {
                return $error->text();
            }
        }
        self::fail(\sprintf('%s has no error (aria-describedby "%s").', $field, $describedBy));
    }

    private function pdf(string $text): string
    {
        return $this->file('preisliste.pdf', ContentFolder::pdf($text.' '.uniqid()));
    }

    private function file(string $name, string $bytes): string
    {
        $this->uploads ??= sys_get_temp_dir().'/cms-documents-page-'.bin2hex(random_bytes(4));
        $directory = $this->uploads.'/'.uniqid();

        return TestImages::write($directory, $name, $bytes);
    }

    private function documentCount(Venue $venue): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_documents WHERE venue_id = ?', [$venue->id()]);
    }

    /**
     * @return list<string>
     */
    private function order(Venue $venue): array
    {
        /* @var list<string> */
        return $this->connection->fetchFirstColumn('SELECT id FROM venue_documents WHERE venue_id = ? ORDER BY position', [$venue->id()]);
    }

    private function title(VenueDocument $document): string
    {
        return (string) $this->connection->fetchOne("SELECT title FROM venue_document_translations WHERE venue_document_id = ? AND locale = 'de'", [$document->id()]);
    }

    private function auditRows(Venue $venue): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $venue->number()]);
    }
}
