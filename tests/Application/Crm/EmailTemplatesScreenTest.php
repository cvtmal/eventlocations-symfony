<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Entity\EmailTemplate;
use App\Crm\ValueObject\MergeFields;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Zenstruck\Foundry\Test\Factories;

/**
 * The mail templates' settings screen (CRM-10): list, form, the unchanged save (P1), delete, and the admin gate.
 */
final class EmailTemplatesScreenTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ForgedPosts;

    private const string LIST = '/admin/crm/vorlagen';

    public function test_the_list_starts_empty(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', self::LIST);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'E-Mail-Vorlagen');
        self::assertSelectorTextContains('.empty__title', 'Keine Vorlagen');
        self::assertSame('/admin/crm/vorlagen/neu', $crawler->filter('.page-header__actions a')->attr('href'));
    }

    public function test_the_list_shows_each_template_with_a_link_to_it(): void
    {
        $this->logInAs();
        $id = $this->template('Erstkontakt', 'Ihre Location');

        $crawler = $this->client->request('GET', self::LIST);

        self::assertSelectorNotExists('.empty__title');
        self::assertSame('Erstkontakt', $crawler->filter('#email-template-list tbody td')->eq(0)->text());
        self::assertSame('Ihre Location', $crawler->filter('#email-template-list tbody td')->eq(1)->text());
        self::assertSame(self::LIST.'/'.$id, $crawler->filter('#email-template-list tbody a')->attr('href'));
    }

    public function test_an_admin_creates_a_template(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', self::LIST.'/neu');
        self::assertResponseIsSuccessful();
        $this->client->submit($this->form($crawler), [
            'email_template[name]' => 'Erstkontakt',
            'email_template[subject]' => 'Ihre Location',
            'email_template[bodyMarkdown]' => "Hallo {contact.first_name}\r\n\r\nGruss",
        ]);

        self::assertResponseRedirects(self::LIST, 303);
        $crawler = $this->client->followRedirect();
        self::assertSame('Vorlage gespeichert.', trim($crawler->filter('.toast')->text()));
        self::assertSame(['Erstkontakt', 'Ihre Location', "Hallo {contact.first_name}\n\nGruss"], array_values((array) $this->connection->fetchAssociative('SELECT name, subject, body_markdown FROM crm_email_templates')));
        self::assertSame(1, $this->auditRows());
    }

    public function test_an_admin_edits_a_template(): void
    {
        $this->logInAs();
        $id = $this->template('Erstkontakt', 'Ihre Location', 'Hallo');

        $crawler = $this->client->request('GET', self::LIST.'/'.$id);

        self::assertSelectorTextContains('h1', 'Erstkontakt');
        self::assertSame('Ihre Location', $crawler->filter('#email_template_subject')->attr('value'));
        self::assertSame('Hallo', $crawler->filter('#email_template_bodyMarkdown')->text());
        $this->client->submit($this->form($crawler), ['email_template[subject]' => 'Ihre neue Location']);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertSame('Ihre neue Location', $this->connection->fetchOne('SELECT subject FROM crm_email_templates WHERE id = ?', [$id]));
        self::assertSame(1, $this->auditRows());
    }

    public function test_a_taken_name_is_refused_on_the_name_field(): void
    {
        $this->logInAs();
        $this->template('Erstkontakt');

        $crawler = $this->client->request('GET', self::LIST.'/neu');
        $this->client->submit($this->form($crawler), [
            'email_template[name]' => 'Erstkontakt',
            'email_template[subject]' => 'Ihre Location',
            'email_template[bodyMarkdown]' => 'Hallo',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#email_template_name_error', 'Es gibt bereits eine Vorlage mit diesem Namen.');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_email_templates'));
        self::assertSame(0, $this->auditRows());
    }

    public function test_a_subject_with_a_line_break_is_refused(): void
    {
        $this->logInAs();

        $this->post(self::LIST.'/neu', ['name' => 'Erstkontakt', 'subject' => "Betreff\nBcc: jemand@example.ch", 'bodyMarkdown' => 'Hallo']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_email_templates'));
    }

    public function test_a_refused_form_renders_again_with_what_was_typed(): void
    {
        $this->logInAs();
        $id = $this->template('Erstkontakt');

        $crawler = $this->client->request('GET', self::LIST.'/'.$id);
        $crawler = $this->client->submit($this->form($crawler), ['email_template[subject]' => '', 'email_template[bodyMarkdown]' => 'Neuer Text']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#email_template_subject[aria-invalid="true"]');
        self::assertSame('Neuer Text', $crawler->filter('#email_template_bodyMarkdown')->text());
        self::assertSelectorExists('#email-template-delete');
    }

    public function test_an_unchanged_save_leaves_no_audit_row(): void
    {
        $this->logInAs();
        $id = $this->template('Erstkontakt', 'Ihre Location', "Zeile eins\n\nZeile zwei");

        $crawler = $this->client->request('GET', self::LIST.'/'.$id);
        // What a browser posts for this textarea: line breaks as CRLF, the row holds LF.
        $this->client->submit($this->form($crawler), ['email_template[bodyMarkdown]' => "Zeile eins\r\n\r\nZeile zwei"]);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertSame(0, $this->auditRows());
    }

    public function test_an_admin_deletes_a_template_through_the_modal(): void
    {
        $this->logInAs();
        $id = $this->template('Erstkontakt');

        $crawler = $this->client->request('GET', self::LIST.'/'.$id);

        self::assertSame('email-template-delete', $crawler->filter('.page-header__actions button')->attr('commandfor'));
        self::assertSelectorExists('#email-template-delete form[method="dialog"] button[autofocus]');
        self::assertSelectorTextContains('#email-template-delete', '«Erstkontakt» wird endgültig gelöscht');
        $this->client->submit($crawler->filter('#email-template-delete form[action$="/loeschen"]')->form());

        self::assertResponseRedirects(self::LIST, 303);
        $crawler = $this->client->followRedirect();
        self::assertSame('Vorlage gelöscht.', trim($crawler->filter('.toast')->text()));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_email_templates'));
        self::assertSame(1, $this->auditRows());
    }

    public function test_deleting_a_template_that_is_gone_answers_404(): void
    {
        $this->logInAs();
        $id = $this->template('Erstkontakt');
        $form = $this->client->request('GET', self::LIST.'/'.$id)->filter('#email-template-delete form[action$="/loeschen"]')->form();
        $this->connection->delete('crm_email_templates', ['id' => $id]);

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_an_editor_is_refused_and_has_no_sidebar_item(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->template('Erstkontakt');

        $crawler = $this->client->request('GET', '/admin');
        self::assertNotContains('E-Mail-Vorlagen', $crawler->filter('.sidebar__label')->each(static fn (Crawler $label): string => $label->text()));

        foreach ([['GET', self::LIST], ['GET', self::LIST.'/neu'], ['POST', self::LIST.'/neu'], ['GET', self::LIST.'/'.$id], ['POST', self::LIST.'/'.$id], ['POST', self::LIST.'/'.$id.'/loeschen']] as [$method, $url]) {
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame(403, $method.' '.$url);
        }
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_email_templates'));
    }

    /**
     * @param array<string, string> $token
     * @param array<string, string> $server
     */
    #[DataProvider('forgedPosts')]
    public function test_a_forged_post_saves_nothing(array $token, array $server): void
    {
        $this->logInAs();

        $this->client->request('POST', self::LIST.'/neu', ['email_template' => ['name' => 'Erstkontakt', 'subject' => 'Ihre Location', 'bodyMarkdown' => 'Hallo'] + $token], server: $server);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('CSRF', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_email_templates'));
    }

    /**
     * @param array<string, string> $token
     * @param array<string, string> $server
     */
    #[DataProvider('forgedPosts')]
    public function test_a_forged_post_deletes_nothing(array $token, array $server): void
    {
        $this->logInAs();
        $id = $this->template('Erstkontakt');

        $this->client->request('POST', self::LIST.'/'.$id.'/loeschen', ['delete_email_template' => $token], server: $server);

        self::assertResponseRedirects(self::LIST.'/'.$id, 303);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_email_templates'));
    }

    public function test_the_help_shows_the_merge_fields_literally(): void
    {
        $this->logInAs();

        $this->client->request('GET', self::LIST.'/neu');

        $help = $this->client->getCrawler()->filter('#email_template_bodyMarkdown_help')->text();
        foreach (MergeFields::TOKENS as $token) {
            self::assertStringContainsString($token, $help);
        }
    }

    private function template(string $name, string $subject = 'Betreff', string $body = 'Text'): string
    {
        $id = EntityId::generate();
        $this->entityManager()->persist(new EmailTemplate($id, $name, $subject, $body));
        $this->entityManager()->flush();

        return $id;
    }

    private function form(Crawler $crawler): Form
    {
        return $crawler->filter('form.form')->form();
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(string $url, array $fields): void
    {
        $this->client->request('POST', $url, ['email_template' => $fields + ['_token' => 'csrf-token']], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
    }

    private function auditRows(): int
    {
        return (int) $this->connection->fetchOne("SELECT count(*) FROM admin_audit_log WHERE subject_type = 'email_template'");
    }
}
