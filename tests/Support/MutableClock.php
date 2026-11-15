<?php

namespace Tests\Support;

use App\Contracts\Clock;
use Carbon\CarbonImmutable;

/**
 * Часы с задаваемым временем для проверок. Позволяют перейти через срок резерва сразу, без реального ожидания.
 */
final class MutableClock implements Clock
{
    /**
     * Запоминает начальный момент проверки. Каждый тест выбирает его явно, чтобы результат не зависел от
     * текущей даты.
     */
    public function __construct(public CarbonImmutable $instant) {}

    /**
     * Возвращает заданный момент. Проверка может изменить его между действиями и увидеть поведение до срока,
     * ровно на сроке и после него.
     */
    public function now(): CarbonImmutable
    {
        return $this->instant;
    }
}
