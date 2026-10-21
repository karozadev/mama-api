<?php

declare(strict_types=1);

namespace App\Enum;

enum UserPlan: string
{
    case Free = 'free';
    case Premium = 'premium';
}
