<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\ContentBlockBodies;
use App\Shared\Extension\ContentBlockDefinition;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The text block editor (#26): one body per enabled locale and the version token of the block's rows when
 * the form was loaded (P8), checked like `LandingTextsData`'s.
 */
final class TextBlockData
{
    /** @var array<string, TextBlockLocaleData> */
    #[Assert\Valid]
    public array $locales = [];

    #[Assert\NotBlank(message: 'cms.block.version.invalid')]
    #[Assert\Regex(pattern: '/\A[0-9a-f]{64}\z/', message: 'cms.block.version.invalid', normalizer: 'mb_scrub')]
    public ?string $version = null;

    /**
     * @param list<string> $locales the enabled locales
     */
    public static function from(ContentBlockDefinition $definition, ContentBlockBodies $bodies, array $locales, string $version): self
    {
        $data = new self();
        $data->version = $version;
        foreach ($locales as $locale) {
            $body = new TextBlockLocaleData();
            $body->body = $bodies->of($locale, $definition->key());
            $data->locales[$locale] = $body;
        }

        return $data;
    }
}
