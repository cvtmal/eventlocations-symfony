<?php

declare(strict_types=1);

namespace App\Venues\Command;

/**
 * Import one content folder (`<root>/venues/<key>/`) into its venue (#9): create, update or
 * skip it when its content hash is unchanged or the CMS edited the venue (#24). One dispatch is one transaction.
 */
final readonly class ImportVenueContent
{
    /**
     * @param string $folder            absolute path of the venue folder
     * @param string $key               the folder name (the venue's content key)
     * @param bool   $dryRun            only read, validate and classify; write nothing
     * @param bool   $force             apply the content even when its hash is unchanged; never over a
     *                                  CMS edit
     * @param bool   $overwriteCmsEdits apply the content to a venue the CMS edited since its last
     *                                  import, even with an unchanged hash, and recreate a venue
     *                                  the CMS deleted (#24, S8)
     */
    public function __construct(
        public string $folder,
        public string $key,
        public bool $dryRun = false,
        public bool $force = false,
        public bool $overwriteCmsEdits = false,
    ) {
    }
}
