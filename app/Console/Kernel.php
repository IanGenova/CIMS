<?php

namespace App\Console;

use App\utils\BackupService;
use App\utils\NotificationService;
use App\utils\WebhookService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /** Whether the database answered this run. Null until first asked. */
    private $databaseDown = null;

    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        'App\Console\Commands\DatabaseBackUp',
        'App\Console\Commands\MarketplaceSync',
        'App\Console\Commands\PostLedger',
        'App\Console\Commands\SystemAudit',
        'App\Console\Commands\TenantCommand',
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // The backup used to be registered with no frequency at all. An event
        // without one inherits Laravel's default expression of "* * * * *", so
        // this was asking for a full database dump every sixty seconds. It now
        // runs hourly and takes a backup only when the configured schedule is
        // actually due, with retention pruning handled inside the service.
        $schedule->call(function () {
            if (BackupService::isDue()) {
                BackupService::run('scheduled');
                BackupService::markRun();
            }
        })->hourly()->name('database-backup')->withoutOverlapping()
          ->skip(function () { return $this->databaseIsDown(); });

        // A basket forgotten on hold at the POS gives its stock back after
        // 12 hours, even when nobody opens the POS to notice.
        $schedule->call(function () {
            \App\Http\Controllers\PosHoldController::releaseForgotten();
        })->hourly()->name('release-forgotten-holds')->withoutOverlapping()
          ->skip(function () { return $this->databaseIsDown(); });

        // Refresh the alerts behind the bell: low stock, expired lots,
        // purchases awaiting approval, customers over their credit limit.
        $schedule->call(function () {
            NotificationService::generateAlerts();
        })->everyThirtyMinutes()->name('generate-alerts')->withoutOverlapping()
          ->skip(function () { return $this->databaseIsDown(); });

        // Push queued webhook deliveries. Kept out of the web request so a
        // slow endpoint cannot stall somebody saving a sale.
        $schedule->call(function () {
            WebhookService::deliverPending(50);
        })->everyFiveMinutes()->name('deliver-webhooks')->withoutOverlapping()
          ->skip(function () { return $this->databaseIsDown(); });

        // Proof of life, and deliberately NOT guarded by databaseIsDown(): it
        // writes a file and nothing else, so it keeps answering even while MySQL
        // is down. That is what separates "the database is not up yet" from
        // "nothing is running the scheduler at all".
        //
        // Nothing outside the application can tell whether the
        // scheduler is being called -- a task that fires and silently fails to
        // reach PHP looks identical to one that works. This writes the time it
        // last ran, so "is the scheduler alive?" has an answer that does not
        // depend on a job happening to have work to do.
        $schedule->call(function () {
            $path = storage_path('framework/scheduler-heartbeat');
            @file_put_contents($path, now()->toDateTimeString());
        })->everyMinute()->name('scheduler-heartbeat')->withoutOverlapping();

        // The books post themselves as documents are saved; this is the net
        // that catches whatever that missed -- a document made from the
        // command line, one whose first attempt threw, one whose status
        // changed later, or anything written while posting was switched off.
        $schedule->command('ledger:post')
            ->everyFiveMinutes()->name('post-ledger')->withoutOverlapping()
            ->skip(function () { return $this->databaseIsDown(); });

        // Marketplace orders in, stock levels out. Every fifteen minutes:
        // often enough that a shop is not overselling for long, rare enough
        // to stay well inside both platforms' rate limits.
        $schedule->command('marketplace:sync')
            ->everyFifteenMinutes()->name('marketplace-sync')->withoutOverlapping()
            ->skip(function () { return $this->databaseIsDown(); });

        // Keep the failed-job table from growing without bound.
        $schedule->command('queue:prune-failed --hours=168')->daily()
            ->skip(function () { return $this->databaseIsDown(); });
    }

    /**
     * Is the database out of reach?
     *
     * The scheduler starts with Windows; MySQL starts with WAMP. For the fifteen
     * or twenty minutes each morning between the two, every job that touches the
     * database threw and wrote a full stack trace -- twenty-one of them over four
     * days, and most of a megabyte of log. That is noise of exactly the kind that
     * hides a real error when one finally appears.
     *
     * Skipping is the honest response rather than a workaround: none of these
     * jobs loses anything by waiting, because each is either periodic or picks up
     * what it missed on its next run. The ledger sweep in particular exists to
     * catch up on whatever was not posted.
     *
     * Worked out once per run and reused, so six jobs do not each sit through
     * their own connection attempt.
     */
    private function databaseIsDown()
    {
        if ($this->databaseDown !== null) {
            return $this->databaseDown;
        }

        try {
            \DB::connection()->getPdo();
            $this->databaseDown = false;
        } catch (\Throwable $e) {
            $this->databaseDown = true;

            // One line, once per run -- not a stack trace per job.
            \Log::warning('Scheduler skipped its database work: the database is not reachable.');
        }

        return $this->databaseDown;
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
