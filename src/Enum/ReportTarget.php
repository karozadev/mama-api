<?php

declare(strict_types=1);

namespace App\Enum;

enum ReportTarget: string
{
    case User = 'user';
    case Dish = 'dish';
    case Review = 'review';
    case Message = 'message';
}
