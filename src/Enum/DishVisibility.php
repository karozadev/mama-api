<?php

declare(strict_types=1);

namespace App\Enum;

enum DishVisibility: string
{
    /** Anyone, including signed-out visitors. */
    case Public = 'public';
    /** The author and the mothers who follow the author. */
    case Followers = 'followers';
    /** The author only. */
    case Private = 'private';
}
