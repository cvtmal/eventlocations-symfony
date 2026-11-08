<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G2 `takedown-form-finish` (D36): three years after the decision, the nightly `app:venues:erase-complainants`
 * erases the complainant's name, e-mail address and message. The case row, its decision and its photo links stay as
 * evidence; `complainant_erased_at` records when the three went.
 *
 * `chk_moderation_cases_erased`: the three are NULL exactly when `complainant_erased_at` is set, and only a decided
 * case is erased. The e-mail CHECK passes a NULL as before.
 *
 * `down()` puts placeholders into erased rows (an empty name and message, `geloescht@invalid`), because the
 * columns are NOT NULL again afterwards; the erased data does not come back.
 */
final class Version20261108074931 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G2 takedown-form-finish: moderation_cases may erase the complainant (D36)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE moderation_cases
                ALTER COLUMN complainant_name DROP NOT NULL,
                ALTER COLUMN complainant_email DROP NOT NULL,
                ALTER COLUMN message DROP NOT NULL,
                ADD COLUMN complainant_erased_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                ADD CONSTRAINT chk_moderation_cases_erased CHECK (
                    (complainant_erased_at IS NULL) = (complainant_name IS NOT NULL)
                    AND (complainant_erased_at IS NULL) = (complainant_email IS NOT NULL)
                    AND (complainant_erased_at IS NULL) = (message IS NOT NULL)
                    AND (complainant_erased_at IS NULL OR status = 'decided')
                )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE moderation_cases DROP CONSTRAINT chk_moderation_cases_erased');
        $this->addSql(<<<'SQL'
            UPDATE moderation_cases
            SET complainant_name = '', complainant_email = 'geloescht@invalid', message = ''
            WHERE complainant_erased_at IS NOT NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE moderation_cases
                DROP COLUMN complainant_erased_at,
                ALTER COLUMN complainant_name SET NOT NULL,
                ALTER COLUMN complainant_email SET NOT NULL,
                ALTER COLUMN message SET NOT NULL
            SQL);
    }
}
