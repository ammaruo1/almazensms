<?php

namespace App\Services;

final class EffectiveQuota
{
    /**
     * Remaining messages this API key may still accept today.
     *
     * The project daily ceiling is an extra guard shared by every key on that
     * project, so one project cannot exceed the configured total. Per-minute
     * limits stay on the key and are not mixed into this daily figure.
     *
     * effective remaining = min(key limit - key used, project ceiling - project used), floored at 0.
     */
    public static function dailyRemaining(int $keyLimit, int $keyUsed, int $projectLimit, int $projectUsed): int
    {
        return max(0, min($keyLimit - $keyUsed, $projectLimit - $projectUsed));
    }
}
