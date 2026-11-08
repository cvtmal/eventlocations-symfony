<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\Command\ChangeOutreach;
use App\Crm\ValueObject\ContactBasis;
use App\Crm\ValueObject\ContactChannel;
use App\Crm\ValueObject\Outreach;
use App\Crm\ValueObject\OutreachCohort;
use App\Shared\Funnel\CampaignCode;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The "Akquise" tab's form (G11): a Location's outreach fields, all optional.
 */
final class OutreachData
{
    #[Assert\Regex(pattern: CampaignCode::PATTERN, message: 'crm.outreach.campaign_code', normalizer: 'trim')]
    public ?string $campaignCode = null;

    public ?OutreachCohort $cohort = null;

    public ?ContactChannel $channel = null;

    public ?ContactBasis $basis = null;

    public ?\DateTimeImmutable $sentOn = null;

    #[Assert\GreaterThanOrEqual(propertyPath: 'sentOn', message: 'crm.outreach.returned_before_sent')]
    public ?\DateTimeImmutable $returnedOn = null;

    public static function of(Outreach $outreach): self
    {
        $data = new self();
        $data->campaignCode = $outreach->campaignCode;
        $data->cohort = $outreach->cohort;
        $data->channel = $outreach->channel;
        $data->basis = $outreach->basis;
        $data->sentOn = $outreach->sentOn;
        $data->returnedOn = $outreach->returnedOn;

        return $data;
    }

    public function toOutreach(): Outreach
    {
        $code = null === $this->campaignCode ? '' : trim($this->campaignCode);

        return new Outreach('' === $code ? null : $code, $this->cohort, $this->channel, $this->basis, $this->sentOn, $this->returnedOn);
    }

    public function toCommand(string $companyId): ChangeOutreach
    {
        return new ChangeOutreach($companyId, $this->toOutreach());
    }
}
