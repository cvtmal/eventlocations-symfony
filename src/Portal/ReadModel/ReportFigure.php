<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

/**
 * One labelled row of the monthly report (G10): `key` is the catalogue suffix (`report.mail.figure.<key>`), the
 * month's value and the month before. A null `previous` renders «–» and no difference.
 */
final readonly class ReportFigure
{
    public function __construct(
        public string $key,
        public int $current,
        public ?int $previous = null,
    ) {
    }

    public function difference(): ?int
    {
        return null === $this->previous ? null : $this->current - $this->previous;
    }
}
