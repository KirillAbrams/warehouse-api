<?php

namespace App\Contracts;

use Carbon\CarbonImmutable;

interface ClockInterface
{
    /**
     * Return fresh UTC time after any lock wait.
     */
    public function now(): CarbonImmutable;
}
