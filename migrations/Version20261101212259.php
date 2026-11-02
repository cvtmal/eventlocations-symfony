<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101212259 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'portal-accounts: portal_users, portal_venue_access and the portal actor of admin_audit_log (#42)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE portal_users (
                id UUID NOT NULL,
                email VARCHAR(180) NOT NULL,
                password_hash VARCHAR(255) DEFAULT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                phone VARCHAR(40) DEFAULT NULL,
                email_verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                is_active BOOLEAN DEFAULT true NOT NULL,
                last_login_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_portal_users PRIMARY KEY (id),
                CONSTRAINT uk_portal_users_email UNIQUE (email),
                CONSTRAINT chk_portal_users_email CHECK (email = lower(email))
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE portal_venue_access (
                portal_user_id UUID NOT NULL,
                venue_id UUID NOT NULL,
                status VARCHAR(20) NOT NULL,
                requested_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                confirmed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_portal_venue_access PRIMARY KEY (portal_user_id, venue_id),
                CONSTRAINT fk_portal_venue_access_portal_user_id FOREIGN KEY (portal_user_id) REFERENCES portal_users (id) ON DELETE CASCADE,
                CONSTRAINT fk_portal_venue_access_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE,
                CONSTRAINT chk_portal_venue_access_status CHECK (status IN ('pending', 'active')),
                CONSTRAINT chk_portal_venue_access_confirmed CHECK ((status = 'active') = (confirmed_at IS NOT NULL))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_portal_venue_access_portal_user_id ON portal_venue_access (portal_user_id)');
        $this->addSql('CREATE INDEX idx_portal_venue_access_venue_id ON portal_venue_access (venue_id)');

        $this->addSql('ALTER TABLE admin_audit_log ADD COLUMN portal_user_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE admin_audit_log ADD CONSTRAINT fk_admin_audit_log_portal_user_id FOREIGN KEY (portal_user_id) REFERENCES portal_users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_admin_audit_log_portal_user_id ON admin_audit_log (portal_user_id)');
        $this->addSql('ALTER TABLE admin_audit_log DROP CONSTRAINT chk_admin_audit_log_actor_type');
        $this->addSql("ALTER TABLE admin_audit_log ADD CONSTRAINT chk_admin_audit_log_actor_type CHECK (actor_type IN ('admin_user', 'console', 'system', 'portal_user'))");
        // One way only: the row of a deleted manager keeps its actor_type while SET NULL clears the id.
        $this->addSql("ALTER TABLE admin_audit_log ADD CONSTRAINT chk_admin_audit_log_portal_user CHECK (portal_user_id IS NULL OR actor_type = 'portal_user')");
    }

    public function down(Schema $schema): void
    {
        // A portal row has no place in the old actor_type list; it goes with the accounts it names.
        $this->addSql("DELETE FROM admin_audit_log WHERE actor_type = 'portal_user'");
        $this->addSql('ALTER TABLE admin_audit_log DROP CONSTRAINT chk_admin_audit_log_portal_user');
        $this->addSql('ALTER TABLE admin_audit_log DROP CONSTRAINT chk_admin_audit_log_actor_type');
        $this->addSql("ALTER TABLE admin_audit_log ADD CONSTRAINT chk_admin_audit_log_actor_type CHECK (actor_type IN ('admin_user', 'console', 'system'))");
        $this->addSql('DROP INDEX idx_admin_audit_log_portal_user_id');
        $this->addSql('ALTER TABLE admin_audit_log DROP CONSTRAINT fk_admin_audit_log_portal_user_id');
        $this->addSql('ALTER TABLE admin_audit_log DROP COLUMN portal_user_id');
        $this->addSql('DROP TABLE portal_venue_access');
        $this->addSql('DROP TABLE portal_users');
    }
}
