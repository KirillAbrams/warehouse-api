<?php

namespace Tests\Feature\Queue;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\Support\RecordQueueProbe;
use Tests\TestCase;

class QueuePayloadTest extends TestCase
{
    public function test_expiration_metadata_does_not_break_unrelated_sync_jobs(): void
    {
        Bus::dispatchSync(new RecordQueueProbe);

        $this->assertTrue(Cache::get('queue-probe-executed'));
    }
}
