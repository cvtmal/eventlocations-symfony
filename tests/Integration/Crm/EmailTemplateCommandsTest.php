<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\DeleteEmailTemplate;
use App\Crm\Command\SaveEmailTemplate;
use App\Crm\Entity\EmailTemplate;
use App\Crm\Exception\EmailTemplateNameTaken;
use App\Crm\Exception\EmailTemplateNotFound;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * CRM-10, D10: a template is created and rewritten by one command, its name is unique, and the audit log names the
 * action and never a value.
 */
final class EmailTemplateCommandsTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use RollsBackDatabase;

    public function test_a_new_template_is_stored_canonical_and_audited_without_a_value(): void
    {
        $id = EntityId::generate();

        $this->dispatch(new SaveEmailTemplate($id, ' Erstkontakt ', ' Ihre Location ', "Hallo {contact.first_name}\r\n\r\nGruss "));

        $this->em->clear();
        $template = $this->em->find(EmailTemplate::class, $id);
        self::assertNotNull($template);
        self::assertSame('Erstkontakt', $template->name());
        self::assertSame('Ihre Location', $template->subject());
        self::assertSame("Hallo {contact.first_name}\n\nGruss", $template->bodyMarkdown());
        self::assertSame([
            ['command' => SaveEmailTemplate::class, 'changes' => ['action' => 'saved']],
        ], $this->auditRows('email_template', $id));
    }

    public function test_saving_an_existing_id_rewrites_it_and_may_keep_its_name(): void
    {
        $id = EntityId::generate();
        $this->dispatch(new SaveEmailTemplate($id, 'Erstkontakt', 'Ihre Location', 'Text'));

        $this->dispatch(new SaveEmailTemplate($id, 'Erstkontakt', 'Ihre Hochzeitslocation', 'Neuer Text'));

        $this->em->clear();
        $template = $this->em->find(EmailTemplate::class, $id);
        self::assertNotNull($template);
        self::assertSame('Ihre Hochzeitslocation', $template->subject());
        self::assertSame('Neuer Text', $template->bodyMarkdown());
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_email_templates WHERE id = ?', $id));
    }

    public function test_a_taken_name_is_refused_on_a_new_template_and_on_a_rename(): void
    {
        $other = EntityId::generate();
        $this->dispatch(new SaveEmailTemplate(EntityId::generate(), 'Erstkontakt', 'Betreff', 'Text'));
        $this->dispatch(new SaveEmailTemplate($other, 'Nachfassen', 'Betreff', 'Text'));

        $this->failure(new SaveEmailTemplate(EntityId::generate(), 'Erstkontakt', 'Betreff', 'Text'), EmailTemplateNameTaken::class);
        $this->failure(new SaveEmailTemplate($other, 'Erstkontakt', 'Betreff', 'Text'), EmailTemplateNameTaken::class);
    }

    public function test_a_template_is_deleted_and_audited(): void
    {
        $id = EntityId::generate();
        $this->dispatch(new SaveEmailTemplate($id, 'Erstkontakt', 'Betreff', 'Text'));

        $this->dispatch(new DeleteEmailTemplate($id));

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_email_templates WHERE id = ?', $id));
        self::assertSame(['action' => 'deleted'], $this->auditRows('email_template', $id)[1]['changes']);
    }

    public function test_an_unknown_id_is_refused_on_delete(): void
    {
        $this->failure(new DeleteEmailTemplate(EntityId::generate()), EmailTemplateNotFound::class);
    }
}
