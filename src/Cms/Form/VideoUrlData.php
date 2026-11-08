<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Venues\Command\AddVenueVideo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The video card's one field (G15): the link as pasted. The form checks only its shape; whether it is a YouTube or
 * Vimeo link the handler decides (`VideoEmbed`, the one allowlist).
 */
final class VideoUrlData
{
    public const int MAX_LENGTH = 500;

    #[Assert\NotBlank(message: 'cms.video.url.required')]
    #[Assert\Length(max: self::MAX_LENGTH, maxMessage: 'cms.video.url.too_long')]
    public ?string $url = null;

    /**
     * Call only after the form validated.
     */
    public function toCommand(int $venueNumber): AddVenueVideo
    {
        return new AddVenueVideo($venueNumber, trim($this->url ?? throw new \LogicException('Validate the form before building its command.')));
    }
}
