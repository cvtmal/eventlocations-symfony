<?php

declare(strict_types=1);

namespace App\Seo;

use App\Seo\ValueObject\PageMeta;
use App\Seo\ValueObject\VenueFacts;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * SEO-1 for a venue. It claims `place` only with coordinates, the two tags that type carries.
 */
final class VenueMeta
{
    public function __construct(
        private readonly TitleChain $titles,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function for(VenueFacts $facts): PageMeta
    {
        $place = $facts->hasCoordinates();

        return new PageMeta(
            $this->titles->fit($this->patterns($facts), $facts->name),
            MetaDescription::fit($facts->description),
            $facts->url,
            // G12: an entry that fails VenueIndexGate is online but `noindex, follow`, and is not in the sitemap.
            !$facts->indexable,
            $place ? 'place' : 'website',
            $place ? $facts->lat : null,
            $place ? $facts->lng : null,
        );
    }

    /**
     * @return list<array{string, array<string, string>}>
     */
    private function patterns(VenueFacts $facts): array
    {
        $name = ['name' => $facts->name];
        $fallback = ['venue.title_name', $name];

        if ($facts->hasTown()) {
            $named = $name + ['town' => (string) $facts->town];

            return [['venue.title_town_long', $named], ['venue.title_town', $named], $fallback];
        }

        if (null !== $facts->canton) {
            return [['venue.title_canton', $name + ['canton' => $facts->canton->trans($this->translator), 'canton_code' => $facts->canton->value]], $fallback];
        }

        return [$fallback];
    }
}
