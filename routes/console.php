<?php

use App\Services\GmailSynchronizer;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('gmail:sync', function (GmailSynchronizer $sync) {
    $queued = 0;
    DB::table('mailbox_connections')->orderBy('id')->each(function ($connection) use ($sync, &$queued) {
        $queued += (int) $sync->request($connection->id);
    });
    $this->info("Queued {$queued} mailbox syncs.");
})->purpose('Queue synchronization for connected Gmail accounts');

Schedule::command('gmail:sync')->everyMinute()->withoutOverlapping();
