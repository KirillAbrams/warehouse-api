<?php

namespace App\Support;

use App\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Часы на основе текущего времени основной базы. Они продолжают идти, пока запрос ждёт чужую блокировку.
 */
final readonly class PostgresClock implements Clock
{
    /**
     * Получает соединение с основной базой, чтобы время решения и данные относились к одному серверу.
     */
    public function __construct(private ConnectionInterface $database) {}

    /**
     * Запрашивает фактическое время базы и переводит его в единый часовой пояс. Время начала операции не
     * используется, поскольку после ожидания оно уже могло устареть.
     */
    public function now(): CarbonImmutable
    {
        /** @var object{instant: string} $result */
        $result = $this->database->selectOne('SELECT clock_timestamp() AS instant');

        return CarbonImmutable::parse($result->instant)->utc();
    }
}
