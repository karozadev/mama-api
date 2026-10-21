<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Market sections used to group the shopping list.
 */
enum IngredientAisle: string
{
    case Meat = 'viandes';
    case Vegetables = 'legumes';
    case Grocery = 'epicerie';
    case Other = 'autre';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
