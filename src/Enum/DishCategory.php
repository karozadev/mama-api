<?php

declare(strict_types=1);

namespace App\Enum;

enum DishCategory: string
{
    case Starter = 'entree';
    case Main = 'plat';
    case Side = 'accompagnement';
    case Dessert = 'dessert';
    case Drink = 'boisson';
    case Snack = 'encas';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
