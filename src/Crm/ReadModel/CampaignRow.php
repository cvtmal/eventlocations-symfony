<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * One postal campaign of the Akquise page (G11): its code and how many Locations it holds, could print and have not
 * been sent yet.
 */
final readonly class CampaignRow
{
    public function __construct(
        public string $code,
        public int $locations,
        public int $printable,
        public int $unsent,
    ) {
    }
}
