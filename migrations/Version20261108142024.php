<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G13 `tier-pages` (D50): the Free tier's display name becomes «Gratis», the word the public page and the copy use.
 * Only the name changes; the code stays `free`, so `KnownTiers::FREE`, the column default and every command that
 * carries a tier code are untouched. down() restores the old name.
 */
final class Version20261108142024 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G13: the Free tier is named Gratis (D50)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE tiers SET name = 'Gratis', updated_at = CURRENT_TIMESTAMP WHERE code = 'free'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE tiers SET name = 'Free', updated_at = CURRENT_TIMESTAMP WHERE code = 'free'");
    }
}
