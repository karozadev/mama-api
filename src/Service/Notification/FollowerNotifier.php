<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Dish;

interface FollowerNotifier
{
    /**
     * @param list<int> $followerIds
     */
    public function notifyDailySpecial(Dish $dish, array $followerIds): void;
}
