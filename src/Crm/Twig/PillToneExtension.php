<?php

declare(strict_types=1);

namespace App\Crm\Twig;

use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\ContactKind;
use Twig\Attribute\AsTwigFunction;

/**
 * The `_pill` tone of a company stage, contact kind or deal stage, one map for the lists, the record pages and
 * `js/admin/crm-record.js` (`crm_tones()` in `data-crm-tones`). A deal stage is a row, not an enum, so a new one
 * is grey until it is listed here.
 */
final readonly class PillToneExtension
{
    private const array TONES = [
        'stage' => ['prospect' => 'blue', 'customer' => 'green', 'former_customer' => 'grey'],
        'kind' => ['venue_contact' => 'blue', 'couple' => 'pink', 'other' => 'grey'],
        'deal' => ['contacted' => 'blue', 'offer' => 'amber', 'negotiation' => 'pink', 'won' => 'green', 'lost' => 'red'],
    ];

    #[AsTwigFunction('crm_tone')]
    public function tone(CompanyStage|ContactKind $value): string
    {
        $tones = $value instanceof CompanyStage ? self::TONES['stage'] : self::TONES['kind'];

        return $tones[$value->value] ?? 'grey';
    }

    #[AsTwigFunction('crm_deal_tone')]
    public function dealTone(string $stage): string
    {
        return self::TONES['deal'][$stage] ?? 'grey';
    }

    /**
     * @return array<string, string>
     */
    #[AsTwigFunction('crm_tones')]
    public function tones(string $group): array
    {
        return self::TONES[$group] ?? throw new \InvalidArgumentException(\sprintf('Unknown pill group "%s".', $group));
    }
}
