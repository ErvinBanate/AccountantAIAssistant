<?php

namespace App\Jobs;

use App\Services\GmailSynchronizer;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncGmailMailbox implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public int $connectionId, public string $generation)
    {
        // Inbox synchronization always uses the durable database queue, including local development.
        $this->onConnection('database');
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function uniqueId(): string
    {
        return $this->connectionId.':'.$this->generation;
    }

    public function handle(GmailSynchronizer $sync): void
    {
        $sync->runPage($this->connectionId, $this->generation);
    }

    public function failed(?Throwable $exception): void
    {
        app(GmailSynchronizer::class)->markFailed($this->connectionId, $this->generation);
    }
}
