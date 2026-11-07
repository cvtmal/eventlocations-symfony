<?php

declare(strict_types=1);

namespace App\Crm;

use App\Shared\Translation\EnabledLocale;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The title of the task a "Pro anfragen" leaves for staff (F12): the venue and the manager's address, in the staff
 * language. The listener and the handler both ask for it, because the open task with this very title is what makes a
 * second request a no-op. `crm_tasks.title` holds 200 characters; a long venue name gives way first, since staff answer
 * to the address.
 */
final class ProRequestTitle
{
    public const int MAX = 200;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly EnabledLocale $locales,
    ) {
    }

    public function of(string $venueName, string $email): string
    {
        $title = $this->compose($venueName, $email);
        $excess = mb_strlen($title) - self::MAX;
        if ($excess > 0) {
            $title = $this->compose(mb_substr($venueName, 0, max(0, mb_strlen($venueName) - $excess - 1)).'…', $email);
        }

        return mb_strlen($title) > self::MAX ? mb_substr($title, 0, self::MAX - 1).'…' : $title;
    }

    private function compose(string $venueName, string $email): string
    {
        return $this->translator->trans('task.pro_request_title', ['venue' => $venueName, 'email' => $email], 'crm', $this->locales->default());
    }
}
