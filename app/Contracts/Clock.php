<?php

namespace App\Contracts;

use Carbon\CarbonImmutable;

interface Clock
{
    /**
     * Return fresh UTC time after any lock wait.
     */
    public function now(): CarbonImmutable;
}
