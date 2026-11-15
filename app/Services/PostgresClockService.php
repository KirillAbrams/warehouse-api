<?php

namespace App\Services;

use App\Contracts\ClockInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

class PostgresClockService implements ClockInterface
{
    /**
     * Use the application connection so clock reads follow its transaction locks.
     *
     * @param ConnectionInterface $database
     */
    public function __construct(private ConnectionInterface $database)
    {
    }

    /**
     * Read PostgreSQL wall-clock time rather than transaction start time.
     *
     * @return CarbonImmutable
     */
    public function now(): CarbonImmutable
    {
        /** @var object{instant: string} $result */
        $result = $this->database->selectOne('SELECT clock_timestamp() AS instant');

        return CarbonImmutable::parse($result->instant)->utc();
    }
}
