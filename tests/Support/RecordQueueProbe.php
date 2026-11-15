<?php

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Небольшое постороннее задание для проверки совместимости очереди. К резервированию оно отношения не имеет.
 */
class RecordQueueProbe implements ShouldQueue
{
    use Queueable;

    /**
     * Ставит отметку о выполнении во временном хранилище. По ней проверка понимает, что обычное задание не
     * сломалось из-за общих настроек сообщений.
     */
    public function handle(): void
    {
        Cache::put('queue-probe-executed', true);
    }
}
