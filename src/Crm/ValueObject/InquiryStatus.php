<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where an inquiry stands in the inbox (CRM-9), stored in `crm_inquiry_links.status`
 * (chk_crm_inquiry_links_status). Staff set it; a page view never does.
 */
enum InquiryStatus: string implements TranslatableInterface
{
    case New = 'new';
    case Seen = 'seen';
    case Done = 'done';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('inquiry.status.'.$this->value, [], 'crm', $locale);
    }
}
