<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Shared\Uid\EntityId;
use App\Venues\ValueObject\PreviewShortCode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A private preview link of an entry (G5): staff issue one per campaign (a mailing, a letter with a
 * QR code), and whoever holds it sees the entry as it would look, without an account. The link's
 * token is derived from this row's id (`Venues\PreviewToken`), so nothing secret is stored here. A
 * link answers until `expiresAt`; `revoke()` ends it for good. Deleting the venue deletes its links.
 */
#[ORM\Entity]
#[ORM\Table(name: 'venue_previews')]
#[ORM\Index(name: 'idx_venue_previews_venue_id', columns: ['venue_id'])]
#[ORM\UniqueConstraint(name: 'uk_venue_previews_short_code', columns: ['short_code'])]
class VenuePreview
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: false, onDelete: 'CASCADE')]
    private Venue $venue;

    /** Null for a link sent by e-mail (G11, PO decision): only a postal letter carries a campaign. */
    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    private ?string $campaignCode;

    /** `/v/<code>` (G11); null only on links issued before G11. */
    #[ORM\Column(type: Types::STRING, length: 10, nullable: true)]
    private ?string $shortCode;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    /**
     * The caller checks the campaign code (`Shared\Funnel\CampaignCode`); the database refuses
     * anything else (`chk_venue_previews_campaign_code`).
     */
    public function __construct(Venue $venue, ?string $campaignCode, \DateTimeImmutable $expiresAt, \DateTimeImmutable $at)
    {
        $this->id = EntityId::generate();
        $this->venue = $venue;
        $this->campaignCode = $campaignCode;
        $this->shortCode = PreviewShortCode::generate();
        $this->expiresAt = $expiresAt;
        $this->createdAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * Ends the link. A second call changes nothing, so the first revocation time stays.
     */
    public function revoke(\DateTimeImmutable $at): void
    {
        if (null !== $this->revokedAt) {
            return;
        }
        $this->revokedAt = $at;
        $this->updatedAt = $at;
    }

    public function id(): string
    {
        \assert(null !== $this->id);

        return $this->id;
    }

    public function venue(): Venue
    {
        return $this->venue;
    }

    public function campaignCode(): ?string
    {
        return $this->campaignCode;
    }

    public function shortCode(): ?string
    {
        return $this->shortCode;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
