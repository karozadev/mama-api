<?php

declare(strict_types=1);

namespace App\Enum;

enum NotificationType: string
{
    case Like = 'like';
    case Review = 'review';
    case Follow = 'follow';
    case DailySpecial = 'daily_special';
    case Collection = 'collection';
    case Cook = 'cook';
}
