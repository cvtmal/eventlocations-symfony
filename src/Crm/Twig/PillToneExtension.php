<?php

declare(strict_types=1);

namespace App\Crm\Twig;

use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\ContactKind;
use Twig\Attribute\AsTwigFunction;

/**
 * The `_pill` tone of a company stage or contact kind, one map for the lists, the record pages and
 * `js/admin/crm-record.js` (`crm_tones()` in `data-crm-tones`).
 */
final readonly class PillToneExtension
{
    private const array TONES = [
        'stage' => ['prospect' => 'blue', 'customer' => 'green', 'former_customer' => 'grey'],
        'kind' => ['venue_contact' => 'blue', 'couple' => 'pink', 'other' => 'grey'],
    ];

    #[AsTwigFunction('crm_tone')]
    public function tone(CompanyStage|ContactKind $value): string
    {
        $tones = $value instanceof CompanyStage ? self::TONES['stage'] : self::TONES['kind'];

        return $tones[$value->value] ?? 'grey';
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
