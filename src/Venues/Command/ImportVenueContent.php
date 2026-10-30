<?php

declare(strict_types=1);

namespace App\Venues\Command;

/**
 * Import one content folder (`<root>/venues/<key>/`) into its venue (#9): create, update or
 * skip it when its content hash is unchanged. One dispatch is one transaction.
 */
final readonly class ImportVenueContent
{
    /**
     * @param string $folder absolute path of the venue folder
     * @param string $key    the folder name (the venue's content key)
     * @param bool   $dryRun only read, validate and classify; write nothing
     * @param bool   $force  apply the content even when its hash is unchanged
     */
    public function __construct(
        public string $folder,
        public string $key,
        public bool $dryRun = false,
        public bool $force = false,
    ) {
    }
}
