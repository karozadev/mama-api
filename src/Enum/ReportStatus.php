<?php

declare(strict_types=1);

namespace App\Enum;

enum ReportStatus: string
{
    case Open = 'open';
    case Dismissed = 'dismissed';
    case Actioned = 'actioned';
}
