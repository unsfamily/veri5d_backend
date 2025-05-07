<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class LogSomethingEveryMinute extends Command
{
    protected $signature = 'log:something';
    protected $description = 'Logs some data every minute';

    public function handle()
    {
        Log::info('Running the cron job at ' . now());
    }
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
    }
}
