<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Fan-out trigger: a daily special has just been published.
 */
final readonly class SendDailySpecialNotification
{
    public function __construct(public int $dishId)
    {
    }
}
