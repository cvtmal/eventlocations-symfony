<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G6 `claim-verify`: one row per code a claimant asked for (`App\Portal\Entity\ClaimChallenge`). The code itself is
 * never stored, only an HMAC of it with `kernel.secret`; a row answers for 30 minutes and five wrong codes, and is
 * used up once `verified_at` is set. Rows older than a day are deleted by the next claim (they only serve the rate
 * limits), so a claimant's name and address do not stay here. Deleting the venue deletes its rows. The campaign code
 * comes from the preview link and follows `funnel_events.campaign_code`'s plain-code rule.
 *
 * `portal_venue_access.competing` marks a pending row a claim wrote while somebody else already held a row on the
 * venue: while one waits, the venue's managers cannot edit (`App\Portal\ReadModel\PortalVenueAccessQuery`).
 */
final class Version20261107212745 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G6: claim_challenges, the mailed codes that prove a claimant\'s address, and portal_venue_access.competing (Portal)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE claim_challenges (
                id UUID NOT NULL,
                venue_id UUID NOT NULL,
                email VARCHAR(180) NOT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                code_hash CHAR(64) NOT NULL,
                attempts SMALLINT DEFAULT 0 NOT NULL,
                campaign_code VARCHAR(40) DEFAULT NULL,
                expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_claim_challenges PRIMARY KEY (id),
                CONSTRAINT fk_claim_challenges_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE,
                CONSTRAINT chk_claim_challenges_email CHECK (email = lower(email) AND position('@' IN email) > 1),
                CONSTRAINT chk_claim_challenges_code_hash CHECK (code_hash ~ '^[0-9a-f]{64}$'),
                CONSTRAINT chk_claim_challenges_attempts CHECK (attempts BETWEEN 0 AND 5),
                CONSTRAINT chk_claim_challenges_campaign_code CHECK (campaign_code ~ '^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$'),
                CONSTRAINT chk_claim_challenges_expiry CHECK (expires_at > created_at)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_claim_challenges_venue_id ON claim_challenges (venue_id)');
        $this->addSql('CREATE INDEX idx_claim_challenges_email_created_at ON claim_challenges (email, created_at)');
        $this->addSql('CREATE INDEX idx_claim_challenges_created_at ON claim_challenges (created_at)');
        $this->addSql('ALTER TABLE portal_venue_access ADD competing BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE portal_venue_access DROP competing');
        $this->addSql('DROP TABLE claim_challenges');
    }
}
