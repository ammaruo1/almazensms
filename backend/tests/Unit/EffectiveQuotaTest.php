<?php

namespace Tests\Unit;

use App\Services\EffectiveQuota;
use PHPUnit\Framework\TestCase;

class EffectiveQuotaTest extends TestCase
{
    public function test_effective_daily_remaining_is_the_smaller_of_key_and_project_ceiling(): void
    {
        $this->assertSame(2, EffectiveQuota::dailyRemaining(10, 3, 5, 3));
        $this->assertSame(0, EffectiveQuota::dailyRemaining(2, 2, 10, 0));
        $this->assertSame(0, EffectiveQuota::dailyRemaining(10, 0, 4, 4));
        $this->assertSame(0, EffectiveQuota::dailyRemaining(1, 5, 8, 0));
    }
}
