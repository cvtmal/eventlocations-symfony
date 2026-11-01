<?php

declare(strict_types=1);

namespace App\Crm\Twig;

use App\Crm\ValueObject\Chf;
use Twig\Attribute\AsTwigFilter;

/**
 * `{{ deal.amountRappen|chf }}`: `CHF 1'250.50` (D6). A `SUM` reaches it as an int because the SQL casts `::bigint`.
 */
final readonly class ChfExtension
{
    #[AsTwigFilter('chf')]
    public function chf(int $rappen): string
    {
        return Chf::display($rappen);
    }
}
