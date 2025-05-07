<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    // protected function schedule(Schedule $schedule)
    // {
    //     // Schedule your commands here
    //     // $schedule->command('example:task')->everyMinute();
    //     // \Log::info('Cron job executed at ' . now());
    //     $schedule->call(function (){ 
    //         \Log::info('Cron job executed at ' . now());  
    //     })->everyMinute();

    //     $schedule->command('log:something')->everyMinute();

    // }
    // // /home/loki/laravel_projects/Veri5d (2)
    // // crontab -e

    protected function schedule(Schedule $schedule)
    {
        $schedule->command('log:something')->everyMinute();
    }


    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
