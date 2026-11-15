<?php

namespace Tests\Support;

use App\Contracts\Clock;
use Carbon\CarbonImmutable;

final class MutableClock implements Clock
{
    public function __construct(public CarbonImmutable $instant) {}

    public function now(): CarbonImmutable
    {
        return $this->instant;
    }
}
