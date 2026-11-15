<?php

namespace Tests\Support;

use App\Contracts\ClockInterface;
use Carbon\CarbonImmutable;

final class MutableClock implements ClockInterface
{
    public function __construct(public CarbonImmutable $instant)
    {
    }

    public function now(): CarbonImmutable
    {
        return $this->instant;
    }
}
