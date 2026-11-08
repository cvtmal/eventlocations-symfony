<?php

declare(strict_types=1);

namespace App\Portal\EventListener;

use App\Portal\Mailer\PortalMailer;
use App\Portal\ReadModel\VenueManagersQuery;
use App\Venues\EntitlementResolver;
use App\Venues\Event\LastMinuteOfferExpired;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * G14 (D55): when the nightly job deleted an offer «Kurzfristig frei», each active manager of the venue gets one mail
 * with a link to the tab, while the venue's plan still allows offers (a venue that fell back to Free gets none: it
 * could not publish a new one). No fallback to the inquiry address (D52). A mail that fails is logged by the mailer.
 */
#[AsEventListener(event: LastMinuteOfferExpired::class)]
final class LastMinuteExpiredMailListener
{
    public function __construct(
        private readonly VenueManagersQuery $managers,
        private readonly PortalMailer $mailer,
        private readonly EntitlementResolver $entitlements,
        private readonly UrlGeneratorInterface $urls,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(LastMinuteOfferExpired $event): void
    {
        if (!$this->entitlements->forVenue($event->venueId, $this->clock->now())->lastMinuteAllowed) {
            return;
        }
        $recipients = $this->managers->mailRecipients($event->venueId);
        if ([] === $recipients) {
            return;
        }

        $url = $this->urls->generate('portal_location_last_minute', ['number' => $event->venueNumber], UrlGeneratorInterface::ABSOLUTE_URL);
        foreach ($recipients as $recipient) {
            $this->mailer->sendLastMinuteExpired($recipient['email'], $recipient['name'], $event->venueName, $event->startsOn, $event->endsOn, $url);
        }
    }
}
