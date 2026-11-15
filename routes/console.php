<?php

/**
 * Периодический поиск просроченных резервов. Запуск раз в минуту восстанавливает задания после остановки
 * обработчика.
 */

use Illuminate\Support\Facades\Schedule;

Schedule::command('reservations:expire')->everyMinute();
