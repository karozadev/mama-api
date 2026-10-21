<?php

declare(strict_types=1);

namespace App\Enum;

enum ReportReason: string
{
    case Spam = 'spam';
    case Harassment = 'harassment';
    case Inappropriate = 'inappropriate';
    case Scam = 'scam';
    case Other = 'other';
}
