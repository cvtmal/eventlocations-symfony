<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\ContentBlockBodies;
use App\Shared\ValueObject\LandingPageEntry;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The landing page editor (#26): one field set per enabled locale and the version token of the page's rows
 * when the form was loaded (P8). The token travels in a `HiddenType`, which `AdminTextConstraintExtension`
 * does not reach, so its shape is checked here.
 */
final class LandingTextsData
{
    /** @var array<string, LandingLocaleTextsData> */
    #[Assert\Valid]
    public array $locales = [];

    #[Assert\NotBlank(message: 'cms.landing.version.invalid')]
    #[Assert\Regex(pattern: '/\A[0-9a-f]{64}\z/', message: 'cms.landing.version.invalid', normalizer: 'mb_scrub')]
    public ?string $version = null;

    /**
     * @param list<string> $locales the enabled locales
     */
    public static function from(LandingPageEntry $entry, ContentBlockBodies $bodies, array $locales, string $version): self
    {
        $data = new self();
        $data->version = $version;
        foreach ($locales as $locale) {
            $texts = new LandingLocaleTextsData();
            $texts->intro = $bodies->of($locale, $entry->blockKey('intro'));
            $texts->title = $bodies->of($locale, $entry->blockKey('title'));
            $texts->description = $bodies->of($locale, $entry->blockKey('description'));
            $data->locales[$locale] = $texts;
        }

        return $data;
    }
}
