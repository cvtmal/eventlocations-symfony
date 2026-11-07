<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Venues\Entity\SourceRecord;

/**
 * Probe for PrivateImportDataProbeTest: a handler may use the records, so this must stay clean. Not part of the
 * application.
 */
final class StoresSourceRecords
{
    public function url(SourceRecord $record): string
    {
        return $record->url();
    }
}
