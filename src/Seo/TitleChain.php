<?php

declare(strict_types=1);

namespace App\Seo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * SEO-1: the first title pattern that fits 60 characters. As a last resort the name is cut at
 * a word and the site suffix stays, because `venues.name` holds up to 150 characters.
 */
final class TitleChain
{
    public const int MAX = 60;

    private const string LAST_RESORT = 'venue.title_name';

    public function __construct(
        private readonly TranslatorInterface $translator,
        #[Autowire('%site.name%')]
        private readonly string $site,
    ) {
    }

    /**
     * @param list<array{string, array<string, string>}> $patterns `seo` keys and their parameters, most specific first
     */
    public function fit(array $patterns, string $name): string
    {
        foreach ($patterns as [$key, $parameters]) {
            $title = $this->title($key, $parameters);
            if (mb_strlen($title) <= self::MAX) {
                return $title;
            }
        }

        $room = self::MAX - mb_strlen($this->title(self::LAST_RESORT, ['name' => '…']));

        return $this->title(self::LAST_RESORT, ['name' => $this->cut($name, $room).'…']);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function title(string $key, array $parameters): string
    {
        return $this->translator->trans($key, $parameters + ['site' => $this->site], 'seo');
    }

    private function cut(string $name, int $length): string
    {
        $head = mb_substr($name, 0, $length);
        $lastSpace = ' ' === mb_substr($name, $length, 1) ? false : mb_strrpos($head, ' ');

        return false === $lastSpace ? $head : mb_substr($head, 0, $lastSpace);
    }
}
