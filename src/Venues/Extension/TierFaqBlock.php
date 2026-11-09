<?php

declare(strict_types=1);

namespace App\Venues\Extension;

use App\Shared\Extension\ContentBlockDefinition;
use App\Shared\Extension\ContentBlockPageDefault;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The FAQ of `/location-eintragen` (G13). Staff edit it in the text blocks screen; until they do, the page shows the
 * `venues` catalogue's questions and answers of `DEFAULT_ENTRIES`, which the block editor quotes in its hint
 * (`pageDefault()`, question then answer). The prices are not in it: the matrix shows them from the tiers.
 */
final readonly class TierFaqBlock implements ContentBlockDefinition, ContentBlockPageDefault
{
    public const string KEY = 'tiers.faq';

    /** Question and answer keys of the default, in page order. */
    public const array DEFAULT_ENTRIES = [
        ['tier_page.faq.cost.question', 'tier_page.faq.cost.answer'],
        ['tier_page.faq.claim.question', 'tier_page.faq.claim.answer'],
        ['tier_page.faq.rights.question', 'tier_page.faq.rights.answer'],
        ['tier_page.faq.inquiries.question', 'tier_page.faq.inquiries.answer'],
        ['tier_page.faq.cancel.question', 'tier_page.faq.cancel.answer'],
        ['tier_page.faq.removal.question', 'tier_page.faq.removal.answer'],
    ];

    public function __construct(
        #[Autowire('%site.contact_email%')]
        private string $contactEmail,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('blocks.tier_faq.label', [], 'venues');
    }

    public function placement(): TranslatableInterface
    {
        return new TranslatableMessage('blocks.tier_faq.placement', [], 'venues');
    }

    public function maxLength(): int
    {
        return 4000;
    }

    public function fallback(): ?TranslatableInterface
    {
        return null;
    }

    public function pageDefault(): array
    {
        $messages = [];
        foreach (self::DEFAULT_ENTRIES as [$question, $answer]) {
            $messages[] = new TranslatableMessage($question, ['email' => $this->contactEmail], 'venues');
            $messages[] = new TranslatableMessage($answer, ['email' => $this->contactEmail], 'venues');
        }

        return $messages;
    }
}
