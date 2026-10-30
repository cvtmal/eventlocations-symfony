<?php

declare(strict_types=1);

namespace App\Admin\Extension;

/**
 * The design draws one widget size; `small` and `wide` only change how many grid columns a widget spans.
 */
enum WidgetSize: string
{
    case Small = 'small';
    case Medium = 'medium';
    case Wide = 'wide';
}
