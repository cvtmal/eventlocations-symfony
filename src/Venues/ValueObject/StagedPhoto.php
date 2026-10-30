<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

use App\Media\ValueObject\GeneratedImage;

/**
 * A new photo before it has a row: its stored original (relative to MEDIA_ROOT) and the
 * variants rendered into a staging directory (PhotoPipeline::stage()).
 */
final readonly class StagedPhoto
{
    public function __construct(
        public string $originalPath,
        public GeneratedImage $image,
        public string $directory,
    ) {
    }
}
