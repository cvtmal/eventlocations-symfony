<?php

declare(strict_types=1);

namespace App\Search\Extension;

use App\Shared\Extension\ContentBlockDefinition;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A text under the h1 of the unfiltered listing, page 1 only (#26, P12). Nothing is shown
 * until staff write one; landing pages have their own intros.
 */
final class ListingIntroBlock implements ContentBlockDefinition
{
    public const string KEY = 'listing.intro';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('blocks.listing_intro.label', [], 'search');
    }

    public function placement(): TranslatableInterface
    {
        return new TranslatableMessage('blocks.listing_intro.placement', [], 'search');
    }

    public function maxLength(): int
    {
        return 600;
    }

    public function fallback(): ?TranslatableInterface
    {
        return null;
    }
}
