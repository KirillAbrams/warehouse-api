<?php

namespace Tests\Feature\Queue;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\Support\RecordQueueProbe;
use Tests\TestCase;

/**
 * Проверяет совместимость общих настроек сообщений с обычными заданиями приложения.
 */
class QueuePayloadTest extends TestCase
{
    /**
     * Выполняет постороннее задание сразу в том же процессе. Отметка результата подтверждает, что обработчик
     * сообщений истечения ему не помешал.
     */
    public function test_expiration_metadata_does_not_break_unrelated_sync_jobs(): void
    {
        Bus::dispatchSync(new RecordQueueProbe);

        $this->assertTrue(Cache::get('queue-probe-executed'));
    }
}
