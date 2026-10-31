<?php

declare(strict_types=1);

namespace App\Shared\Extension;

use App\Shared\ValueObject\LandingPageEntry;

/**
 * The landing pages staff can write texts for (#26), with what each shows when nothing is set.
 * Shared holds the contract so the back office (Cms) lists them without naming Search or Seo;
 * Seo implements it, because the default title and description are Seo's.
 */
interface LandingPageSource
{
    /**
     * Every page with at least one published venue, live or under the threshold.
     *
     * @return list<LandingPageEntry>
     */
    public function pages(): array;

    /**
     * The page with this key prefix (`canton.zh`, `town.<uuid>`, `building.<code>`), or null.
     */
    public function page(string $keyPrefix): ?LandingPageEntry;
}
