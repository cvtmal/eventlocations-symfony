<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * How the importer found an image reference on a page.
 */
enum CandidateOrigin: string
{
    case Img = 'img';
    case Srcset = 'srcset';
    case Picture = 'picture';
    case OgImage = 'og_image';
    case TwitterImage = 'twitter_image';
    case LinkRel = 'link_rel';
    case JsonLd = 'json_ld';
    case CssBackground = 'css_background';
}
