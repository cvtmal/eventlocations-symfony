<?php

declare(strict_types=1);

namespace App\Cms\Entity;

use App\Cms\Repository\ContentBlockRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One staff-edited text in one locale (#26): a module's block (listing.intro, footer.note) or a
 * landing page's field (landing.canton.zh.title). The natural key (key, locale) is the primary
 * key, a documented exception to EntityId like cantons. A block without text has no row.
 */
#[ORM\Entity(repositoryClass: ContentBlockRepository::class)]
#[ORM\Table(name: 'content_blocks')]
class ContentBlock
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $key;

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 5)]
    private string $locale;

    #[ORM\Column(type: Types::TEXT)]
    private string $bodyMarkdown;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $key, string $locale, string $body, \DateTimeImmutable $now)
    {
        $this->key = $key;
        $this->locale = $locale;
        $this->bodyMarkdown = $body;
        $this->updatedAt = $now;
    }

    /**
     * @return bool false when the body is unchanged, which leaves updated_at alone
     */
    public function revise(string $body, \DateTimeImmutable $now): bool
    {
        if ($body === $this->bodyMarkdown) {
            return false;
        }

        $this->bodyMarkdown = $body;
        $this->updatedAt = $now;

        return true;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function body(): string
    {
        return $this->bodyMarkdown;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
