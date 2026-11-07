<?php

declare(strict_types=1);

namespace App\Crm;

use App\Venues\ReadModel\TierOptionsQuery;

/**
 * The tiers a deal can sell, in the two shapes the CRM asks `TierOptionsQuery` for (F12): the create form's choices and
 * the codes the inline edit checks. Each call is one statement, so a caller asks only when it needs the answer.
 */
final readonly class TierChoices
{
    public function __construct(private TierOptionsQuery $options)
    {
    }

    /**
     * Name to code, best first: the shape of a ChoiceType's `choices`.
     *
     * @return array<string, string>
     */
    public function choices(): array
    {
        return $this->options->choices();
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_values($this->choices());
    }
}
