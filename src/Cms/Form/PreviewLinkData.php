<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Shared\Funnel\CampaignCode;
use App\Venues\Command\IssueVenuePreview;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The form behind "Vorschau-Link erstellen" (G5): the campaign the link belongs to (a plain code, as
 * the funnel stores it) and how many days it answers.
 */
final class PreviewLinkData
{
    /** Empty for a link sent by e-mail (G11, PO decision): only a postal letter carries a campaign. */
    #[Assert\Regex(pattern: CampaignCode::PATTERN, message: 'cms.preview_link.campaign.invalid', normalizer: 'mb_scrub')]
    public ?string $campaignCode = null;

    #[Assert\NotNull(message: 'cms.preview_link.lifetime.invalid')]
    #[Assert\Choice(choices: IssueVenuePreview::LIFETIMES, message: 'cms.preview_link.lifetime.invalid')]
    public ?int $lifetimeDays = IssueVenuePreview::DEFAULT_LIFETIME;

    public function toCommand(int $venueNumber): IssueVenuePreview
    {
        return new IssueVenuePreview(
            $venueNumber,
            null === $this->campaignCode || '' === trim($this->campaignCode) ? null : trim($this->campaignCode),
            $this->lifetimeDays ?? throw new \LogicException('Validate the form before building its command.'),
        );
    }
}
