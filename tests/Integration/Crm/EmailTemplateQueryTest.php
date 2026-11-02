<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Entity\EmailTemplate;
use App\Crm\ReadModel\EmailTemplateQuery;
use App\Crm\ReadModel\EmailTemplateRow;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EmailTemplateQueryTest extends KernelTestCase
{
    use RollsBackDatabase;

    public function test_all_and_options_order_by_name_whatever_its_case(): void
    {
        $this->connection->executeStatement('DELETE FROM crm_email_templates');
        $gamma = $this->template('Gamma');
        $alpha = $this->template('alpha');
        $beta = $this->template('Beta');

        self::assertSame(['alpha', 'Beta', 'Gamma'], array_map(static fn (EmailTemplateRow $row): string => $row->name, $this->query()->all()));
        self::assertSame([
            ['id' => $alpha->id(), 'name' => 'alpha'],
            ['id' => $beta->id(), 'name' => 'Beta'],
            ['id' => $gamma->id(), 'name' => 'Gamma'],
        ], $this->query()->options());
    }

    public function test_find_gives_the_row_with_its_raw_merge_fields(): void
    {
        $template = $this->template('Erstkontakt');

        $found = $this->query()->find($template->id());

        self::assertNotNull($found);
        self::assertSame($template->id(), $found->id);
        self::assertSame('Erstkontakt', $found->name);
        self::assertSame('Grüezi {contact.first_name}', $found->subject);
        self::assertSame('Ihre Location {venue.name}', $found->bodyMarkdown);
        self::assertSame($template->updatedAt()->getTimestamp(), $found->updatedAt->getTimestamp());
    }

    public function test_find_gives_null_for_an_unknown_id_and_a_non_uuid(): void
    {
        self::assertNull($this->query()->find(EntityId::generate()));
        self::assertNull($this->query()->find('kein-uuid'));
    }

    private function template(string $name): EmailTemplate
    {
        $template = new EmailTemplate(EntityId::generate(), $name, 'Grüezi {contact.first_name}', 'Ihre Location {venue.name}');
        $this->em->persist($template);
        $this->em->flush();

        return $template;
    }

    private function query(): EmailTemplateQuery
    {
        return new EmailTemplateQuery($this->connection);
    }
}
