<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * Why SafeFetcher refused a URL or stopped a download. The value is what a report prints, so it stays a short
 * stable word; the detail next to it (FetchRefused::$detail) says which address, limit or status.
 */
enum RefusalReason: string
{
    /** Anything but http and https. */
    case Scheme = 'scheme';
    /** `user:password@` in the URL. */
    case Credentials = 'credentials';
    /** Not parseable, control characters, a backslash, empty host, too long. */
    case Malformed = 'malformed';
    /** Another port than 80 for http or 443 for https. */
    case Port = 'port';
    /** localhost aliases, IP addresses and numeric spellings instead of a host name, reserved suffixes. */
    case HostName = 'host_name';
    /** The host (or its IP) is not on the public internet. */
    case BlockedAddress = 'blocked_address';
    /** DNS knows no address for the host. */
    case Unresolvable = 'unresolvable';
    /** Too many redirects, a loop, a missing or unusable Location. */
    case Redirect = 'redirect';
    /** The page or a redirect leaves the site the crawl started on. */
    case CrossHost = 'cross_host';
    /** robots.txt disallows the URL, or could not be read. */
    case Robots = 'robots';
    /** Not a content type the caller asked for. */
    case ContentType = 'content_type';
    /** One response is over its byte cap. */
    case TooLarge = 'too_large';
    /** The crawl's total bytes are used up. */
    case ByteBudget = 'byte_budget';
    /** The crawl's page count is used up. */
    case PageLimit = 'page_limit';
    /** The crawl's time is used up. */
    case TimeLimit = 'time_limit';
    /** The server answered 4xx or 5xx. */
    case HttpStatus = 'http_status';
    /** The connection failed or timed out. */
    case Transport = 'transport';
    /** The connection went to another address than the vetted one. */
    case AddressChanged = 'address_changed';
}
