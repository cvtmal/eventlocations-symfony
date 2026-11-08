<?php

declare(strict_types=1);

namespace App\Portal\Form;

use App\Venues\Command\PublishLastMinuteOffer;
use App\Venues\ValueObject\LastMinuteLimits;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The shape of an offer «Kurzfristig frei» in `/konto` (G14). The rules on dates, room and event types are the
 * handler's (`PublishLastMinuteOffer`); this checks what a field can hold.
 */
final class LastMinuteOfferData
{
    #[Assert\NotNull]
    public ?\DateTimeImmutable $startsOn = null;

    public ?\DateTimeImmutable $endsOn = null;

    public ?string $roomId = null;

    /** @var list<string> */
    public array $eventTypes = [];

    #[Assert\NotBlank]
    #[Assert\Length(max: LastMinuteLimits::PRICE_TEXT_MAX)]
    public ?string $priceText = null;

    #[Assert\Range(min: 1, max: LastMinuteLimits::MIN_GUESTS_MAX)]
    public ?int $minGuests = null;

    #[Assert\Length(max: LastMinuteLimits::TERMS_MAX)]
    public ?string $terms = null;

    public function toCommand(int $venueNumber): PublishLastMinuteOffer
    {
        return new PublishLastMinuteOffer(
            $venueNumber,
            $this->startsOn ?? throw new \LogicException('Validate the form before building its command.'),
            $this->endsOn,
            '' === $this->roomId ? null : $this->roomId,
            $this->eventTypes,
            $this->priceText ?? throw new \LogicException('Validate the form before building its command.'),
            $this->minGuests,
            $this->terms,
        );
    }
}
