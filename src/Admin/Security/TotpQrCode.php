<?php

declare(strict_types=1);

namespace App\Admin\Security;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Renders the provisioning URI as inline SVG on the server: the secret never leaves for a QR service.
 */
final class TotpQrCode
{
    private const int SIZE = 200;

    public function svg(string $provisioningUri): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(self::SIZE), new SvgImageBackEnd())))->writeString($provisioningUri);

        // Drops the XML prolog, which is not valid inside HTML.
        return trim((string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg));
    }
}
