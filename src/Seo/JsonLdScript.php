<?php

declare(strict_types=1);

namespace App\Seo;

/**
 * One JSON-LD block. JSON_HEX_TAG keeps a `</script>` in a venue name from ending it.
 */
final class JsonLdScript
{
    /**
     * @param array<string, mixed> $data
     */
    public static function render(array $data): string
    {
        return \sprintf(
            '<script type="application/ld+json">%s</script>',
            json_encode($data, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
        );
    }
}
