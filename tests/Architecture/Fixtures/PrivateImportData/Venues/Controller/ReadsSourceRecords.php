<?php

declare(strict_types=1);

namespace App\Venues\Controller;

use App\Venues\Entity\SourceRecord;

/**
 * Probe for PrivateImportDataProbeTest: a controller that reads an imported record. Not part of the application.
 */
final class ReadsSourceRecords
{
    public function url(SourceRecord $record): string
    {
        return $record->url();
    }
}
