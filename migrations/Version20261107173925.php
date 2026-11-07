<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F9 `inquiry-event`: the event fields of an inquiry as typed columns of `inquiries`; the wedding `occasion` goes.
 *
 * - event_type is a `suitable_for` code of `taxonomy_terms` (F3), the one source of the event types. It is no foreign
 *   key: an inquiry is a snapshot kept for 12 months, and a later vocabulary change must neither cascade into it nor
 *   be blocked by it. The handler checks the code against the vocabulary; the CHECK pins the shape of a term code.
 * - catering_wish is one of the two "who caters" codes of the `catering` group (in_house, external_allowed); NULL is
 *   "no wish". A test pins the list to the vocabulary.
 * - event_date or date_flexible: an inquiry names a date, says the date is flexible, or both.
 * - start_time, company and budget_chf are optional. The budget is whole francs.
 * - There is no production data. The occasion values map onto the event types (wedding, civil wedding, wedding apéro
 *   and other to wedding; engagement to family_celebration), and down() maps back.
 */
final class Version20261107173925 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F9 inquiry-event: event_type, company, date_flexible, start_time, catering_wish and budget_chf on inquiries; occasion goes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE inquiries
                ADD COLUMN event_type VARCHAR(40) DEFAULT NULL,
                ADD COLUMN company VARCHAR(150) DEFAULT NULL,
                ADD COLUMN date_flexible BOOLEAN DEFAULT false NOT NULL,
                ADD COLUMN start_time TIME(0) WITHOUT TIME ZONE DEFAULT NULL,
                ADD COLUMN catering_wish VARCHAR(40) DEFAULT NULL,
                ADD COLUMN budget_chf INTEGER DEFAULT NULL
            SQL);
        $this->addSql("UPDATE inquiries SET event_type = CASE occasion WHEN 'engagement' THEN 'family_celebration' ELSE 'wedding' END");
        $this->addSql('UPDATE inquiries SET date_flexible = true WHERE event_date IS NULL');
        $this->addSql('ALTER TABLE inquiries ALTER COLUMN event_type SET NOT NULL');
        $this->addSql('ALTER TABLE inquiries DROP CONSTRAINT chk_inquiries_occasion');
        $this->addSql('ALTER TABLE inquiries DROP COLUMN occasion');
        $this->addSql(<<<'SQL'
            ALTER TABLE inquiries
                ADD CONSTRAINT chk_inquiries_event_type CHECK (event_type ~ '^[a-z][a-z0-9_]*$'),
                ADD CONSTRAINT chk_inquiries_company CHECK (company IS NULL OR char_length(btrim(company)) BETWEEN 1 AND 150),
                ADD CONSTRAINT chk_inquiries_event_date_or_flexible CHECK (event_date IS NOT NULL OR date_flexible),
                ADD CONSTRAINT chk_inquiries_catering_wish CHECK (catering_wish IN ('in_house', 'external_allowed')),
                ADD CONSTRAINT chk_inquiries_budget_chf CHECK (budget_chf BETWEEN 1 AND 1000000)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inquiries ADD COLUMN occasion VARCHAR(30) DEFAULT NULL');
        $this->addSql("UPDATE inquiries SET occasion = CASE event_type WHEN 'wedding' THEN 'wedding' WHEN 'family_celebration' THEN 'engagement' ELSE 'other' END");
        $this->addSql('ALTER TABLE inquiries ALTER COLUMN occasion SET NOT NULL');
        $this->addSql("ALTER TABLE inquiries ADD CONSTRAINT chk_inquiries_occasion CHECK (occasion IN ('wedding', 'civil_wedding', 'wedding_aperitif', 'engagement', 'other'))");
        $this->addSql(<<<'SQL'
            ALTER TABLE inquiries
                DROP COLUMN event_type,
                DROP COLUMN company,
                DROP COLUMN date_flexible,
                DROP COLUMN start_time,
                DROP COLUMN catering_wish,
                DROP COLUMN budget_chf
            SQL);
    }
}
