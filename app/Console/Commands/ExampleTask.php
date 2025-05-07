<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ExampleTask extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:example-task';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Your logic here
        \Log::info('Cron job executed at ' . now());
    }
}
