<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Staff accounts and the audit log of the back office (#22, S12, ADM-1–ADM-3).
 *
 * e-mail is stored lower-case: chk_admin_users_email plus the plain unique key give the
 * "unique on lower(email)" rule without an expression index. admin_audit_log is append-only, so it
 * has no updated_at; actor_type makes "console" explicit, and only an admin_user row has an actor.
 * Users are deactivated, never deleted, hence RESTRICT.
 */
final class Version20261030152714 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'admin-foundation: admin_users and admin_audit_log (#22)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE admin_users (
                id UUID NOT NULL,
                email VARCHAR(180) NOT NULL,
                display_name VARCHAR(100) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(10) NOT NULL,
                totp_secret_encrypted TEXT DEFAULT NULL,
                is_active BOOLEAN DEFAULT true NOT NULL,
                last_login_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_admin_users PRIMARY KEY (id),
                CONSTRAINT uk_admin_users_email UNIQUE (email),
                CONSTRAINT chk_admin_users_email CHECK (email = lower(email)),
                CONSTRAINT chk_admin_users_role CHECK (role IN ('admin', 'editor')),
                CONSTRAINT chk_admin_users_display_name CHECK (btrim(display_name) <> '')
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE admin_audit_log (
                id UUID NOT NULL,
                actor_type VARCHAR(20) NOT NULL,
                actor_id UUID DEFAULT NULL,
                command VARCHAR(200) NOT NULL,
                subject_type VARCHAR(50) NOT NULL,
                subject_id VARCHAR(64) NOT NULL,
                changes JSONB NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_admin_audit_log PRIMARY KEY (id),
                CONSTRAINT fk_admin_audit_log_actor_id FOREIGN KEY (actor_id) REFERENCES admin_users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_admin_audit_log_actor_type CHECK (actor_type IN ('admin_user', 'console', 'system')),
                CONSTRAINT chk_admin_audit_log_actor CHECK ((actor_type = 'admin_user') = (actor_id IS NOT NULL)),
                CONSTRAINT chk_admin_audit_log_changes CHECK (jsonb_typeof(changes) = 'object')
            )
            SQL);

        $this->addSql('CREATE INDEX idx_admin_audit_log_actor_id ON admin_audit_log (actor_id)');
        $this->addSql('CREATE INDEX idx_admin_audit_log_subject ON admin_audit_log (subject_type, subject_id, created_at)');
        $this->addSql('CREATE INDEX idx_admin_audit_log_created_at ON admin_audit_log (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_audit_log');
        $this->addSql('DROP TABLE admin_users');
    }
}
