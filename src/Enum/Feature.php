<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Features that may become premium-only once the app is monetised.
 */
enum Feature: string
{
    case UnlimitedDishes = 'unlimited_dishes';
    case AdvancedStats = 'advanced_stats';
    case AdFree = 'ad_free';
}
