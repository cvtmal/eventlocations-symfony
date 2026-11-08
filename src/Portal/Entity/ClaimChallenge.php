<?php

declare(strict_types=1);

namespace App\Portal\Entity;

use App\Portal\Repository\ClaimChallengeRepository;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One code a claimant asked for (G6): it proves the address it was mailed to. Only the code's HMAC is kept
 * (`Portal\ClaimCode`). A challenge answers for LIFETIME and MAX_ATTEMPTS wrong codes and is used up by the right
 * one; after that a new code is needed. The names wait here until the code is right, when the account of that
 * address is found or created.
 */
#[ORM\Entity(repositoryClass: ClaimChallengeRepository::class)]
#[ORM\Table(name: 'claim_challenges')]
#[ORM\Index(name: 'idx_claim_challenges_venue_id', columns: ['venue_id'])]
#[ORM\Index(name: 'idx_claim_challenges_email_created_at', columns: ['email', 'created_at'])]
#[ORM\Index(name: 'idx_claim_challenges_created_at', columns: ['created_at'])]
class ClaimChallenge
{
    public const string LIFETIME = '+30 minutes';
    public const int MAX_ATTEMPTS = 5;

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: false, onDelete: 'CASCADE')]
    private Venue $venue;

    #[ORM\Column(type: Types::STRING, length: 180)]
    private string $email;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $firstName;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $lastName;

    #[ORM\Column(type: Types::STRING, length: 64, options: ['fixed' => true])]
    private string $codeHash;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    private ?string $campaignCode;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    /**
     * `$at` in whole seconds: the TIMESTAMP(0) columns would round a fraction, and the expiry is compared in PHP.
     */
    public function __construct(string $id, Venue $venue, string $email, string $firstName, string $lastName, string $codeHash, ?string $campaignCode, \DateTimeImmutable $at)
    {
        $this->id = $id;
        $this->venue = $venue;
        $this->email = mb_strtolower($email);
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->codeHash = $codeHash;
        $this->campaignCode = $campaignCode;
        $this->createdAt = $at;
        $this->updatedAt = $at;
        $this->expiresAt = $at->modify(self::LIFETIME);
    }

    /**
     * Whether a code may still be tried: not used, not expired, attempts left.
     */
    public function isOpen(\DateTimeImmutable $now): bool
    {
        return null === $this->verifiedAt && $now < $this->expiresAt && $this->attempts < self::MAX_ATTEMPTS;
    }

    public function failAttempt(\DateTimeImmutable $at): void
    {
        $this->attempts = min(self::MAX_ATTEMPTS, $this->attempts + 1);
        $this->updatedAt = $at;
    }

    public function verify(\DateTimeImmutable $at): void
    {
        $this->verifiedAt = $at;
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

    public function email(): string
    {
        return $this->email;
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    public function codeHash(): string
    {
        return $this->codeHash;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function campaignCode(): ?string
    {
        return $this->campaignCode;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function verifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
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
