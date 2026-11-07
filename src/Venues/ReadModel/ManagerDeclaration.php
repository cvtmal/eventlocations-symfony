<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

/**
 * One manager's latest rights declaration for a venue (G1): when the manager gave it and the version of the
 * text the manager agreed to. A later upload of the same manager is covered by it and records that version,
 * not the text that happens to be in force on the day of the upload.
 */
final readonly class ManagerDeclaration
{
    public function __construct(
        public \DateTimeImmutable $at,
        public string $policyVersion,
    ) {
    }
}
