<?php

declare(strict_types=1);

namespace App\Stats\ValueObject;

/**
 * A figure of the daily rollup (#47, contract C5), stored in `stats_daily.metric` (chk_stats_daily_metric).
 * The page-view cases are site rows (venue_number 0); the rest are venue rows.
 */
enum Metric: string
{
    case PageView = 'page_view';
    case PageViewHome = 'page_view_home';
    case PageViewListing = 'page_view_listing';
    case PageViewLanding = 'page_view_landing';
    case PageViewDetail = 'page_view_detail';
    case PageViewMerkliste = 'page_view_merkliste';
    case PageViewOther = 'page_view_other';
    case DetailView = 'detail_view';
    case Impression = 'impression';
    case ClickWebsite = 'click_website';
    case ClickEmail = 'click_email';
    case ClickPhone = 'click_phone';
    case ClickMap = 'click_map';
    case ClickInstagram = 'click_instagram';
    case MerklisteAdd = 'merkliste_add';
    case Inquiry = 'inquiry';
}
