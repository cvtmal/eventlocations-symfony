<?php

declare(strict_types=1);

namespace App\Crm\Twig;

use App\Crm\ValueObject\CompanyStage;
use App\Inquiries\ValueObject\InquiryOverride;
use App\Inquiries\ValueObject\Qualification;
use Twig\Attribute\AsTwigFunction;

/**
 * The `_pill` tone of a company stage or deal stage, one map for the lists, the record pages and
 * `js/admin/crm-record.js` (`crm_tones()` in `data-crm-tones`). A deal stage is a row, not an enum, so a new one
 * is grey until it is listed here.
 */
final readonly class PillToneExtension
{
    private const array TONES = [
        'stage' => ['prospect' => 'blue', 'customer' => 'green', 'former_customer' => 'grey'],
        'deal' => ['contacted' => 'blue', 'offer' => 'amber', 'negotiation' => 'pink', 'won' => 'green', 'lost' => 'red'],
        'inquiry' => ['new' => 'pink', 'seen' => 'blue', 'done' => 'green'],
        'mail' => ['sent' => 'green', 'failed' => 'red', 'pending' => 'amber', 'skipped' => 'grey'],
        'qualification' => ['qualified' => 'green', 'unqualified' => 'grey'],
        'override' => ['spam' => 'red', 'test' => 'grey'],
    ];

    #[AsTwigFunction('crm_tone')]
    public function tone(CompanyStage $value): string
    {
        return self::TONES['stage'][$value->value];
    }

    #[AsTwigFunction('crm_qualification_tone')]
    public function qualificationTone(Qualification $value): string
    {
        return self::TONES['qualification'][$value->value];
    }

    #[AsTwigFunction('crm_override_tone')]
    public function overrideTone(InquiryOverride $value): string
    {
        return self::TONES['override'][$value->value];
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
