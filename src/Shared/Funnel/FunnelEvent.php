<?php

declare(strict_types=1);

namespace App\Shared\Funnel;

/**
 * The steps of the funnel scorecard (G8), in funnel order. The value is the stored name
 * (`funnel_events.event`, pinned by `chk_funnel_events_event`); a new case needs a migration that
 * replaces that CHECK. Dispatch one through `Shared\Command\RecordFunnelEvent`.
 *
 * Who emits what (a step is emitted once, by the module that knows it happened):
 *  - PreviewOpened (G5), ClaimStarted and ClaimVerified (G6): Portal / Venues previews;
 *  - VenuePublished: `PublishVenueHandler`, on every transition to online (already wired);
 *  - InquiryDelivered: `DeliverInquiryHandler`, when the venue mail went out (already wired);
 *  - ProPaid and SubscriptionCancelled (G9), BriefSent (F13: one brief, however many venues).
 */
enum FunnelEvent: string
{
    case PreviewOpened = 'preview_opened';
    case ClaimStarted = 'claim_started';
    case ClaimVerified = 'claim_verified';
    case VenuePublished = 'venue_published';
    case InquiryDelivered = 'inquiry_delivered';
    case ProPaid = 'pro_paid';
    case SubscriptionCancelled = 'subscription_cancelled';
    case BriefSent = 'brief_sent';
}
