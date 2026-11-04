<?php

declare(strict_types=1);

namespace App\Geo\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The 26 Swiss cantons in BFS order (1 ZH … 26 JU), then Liechtenstein (`li`, pseudo BFS number 27, #46).
 *
 * The value is the lower-case code used as the `cantons` primary key and in query
 * parameters (S4). Labels come from the `geo` catalogue (`canton.<code>`); slug, SVG id
 * and centroid live in the `cantons` table.
 */
enum Canton: string implements TranslatableInterface
{
    case ZH = 'zh';
    case BE = 'be';
    case LU = 'lu';
    case UR = 'ur';
    case SZ = 'sz';
    case OW = 'ow';
    case NW = 'nw';
    case GL = 'gl';
    case ZG = 'zg';
    case FR = 'fr';
    case SO = 'so';
    case BS = 'bs';
    case BL = 'bl';
    case SH = 'sh';
    case AR = 'ar';
    case AI = 'ai';
    case SG = 'sg';
    case GR = 'gr';
    case AG = 'ag';
    case TG = 'tg';
    case TI = 'ti';
    case VD = 'vd';
    case VS = 'vs';
    case NE = 'ne';
    case GE = 'ge';
    case JU = 'ju';
    case LI = 'li';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('canton.'.$this->value, [], 'geo', $locale);
    }

    /** ISO 3166-1 alpha-2 of the country the region belongs to. */
    public function countryCode(): string
    {
        return self::LI === $this ? 'LI' : 'CH';
    }
}
