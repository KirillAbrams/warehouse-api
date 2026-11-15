<?php

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RecordQueueProbe implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::put('queue-probe-executed', true);
    }
}
