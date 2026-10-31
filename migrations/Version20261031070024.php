<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Staff-edited text blocks (#26): a landing page's intro, SEO title and meta description, and
 * the named Markdown blocks modules declare (listing.intro, footer.note).
 *
 * - The natural key (key, locale) is the primary key, a documented exception to EntityId like
 *   cantons: a block is addressed by its name, and one row per locale exists at most.
 * - key is dotted: a module's block (listing.intro), or landing.<type>.<id>.<field> for a
 *   landing page (landing.canton.zh.title). Building codes carry an underscore.
 * - A block without text has no row, so an empty body is refused; clearing a field deletes it.
 * - updated_at holds whole seconds, so the editor's stale check hashes key|locale|updated_at|sha256(body) (ContentVersion::of()).
 */
final class Version20261031070024 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cms-pages: content_blocks (#26)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE content_blocks (
                key VARCHAR(120) NOT NULL,
                locale VARCHAR(5) NOT NULL,
                body_markdown TEXT NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_content_blocks PRIMARY KEY (key, locale),
                CONSTRAINT chk_content_blocks_key CHECK (key ~ '^[a-z][a-z0-9_]*(\.[a-z0-9_-]+)+$'),
                CONSTRAINT chk_content_blocks_body CHECK (btrim(body_markdown) <> ''),
                CONSTRAINT chk_content_blocks_locale CHECK (locale ~ '^[a-z]{2}$')
            )
            SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON TABLE content_blocks IS 'Staff-edited Markdown per locale (#26). key: a module block (listing.intro, footer.note) or landing.<canton|town|building>.<code|town id>.<intro|title|description>.'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content_blocks');
    }
}
