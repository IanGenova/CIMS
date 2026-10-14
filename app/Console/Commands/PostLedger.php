<?php

namespace App\Console\Commands;

use App\utils\LedgerPoster;
use Illuminate\Console\Command;

class PostLedger extends Command
{
    protected $signature = 'ledger:post
                            {--limit=500 : How many documents of each kind to post in one pass}
                            {--force : Post even when automatic posting is switched off}';

    protected $description = 'Post sales, purchases and expenses that have not reached the journal yet';

    public function handle()
    {
        if (! LedgerPoster::isEnabled() && ! $this->option('force')) {
            $this->warn('Automatic posting is switched off. Use --force to post anyway.');

            return 0;
        }

        $waiting = LedgerPoster::pendingCount();
        if ($waiting['total'] === 0) {
            $this->info('Nothing to post.');

            return 0;
        }

        $this->line(sprintf('Waiting: %d sale(s), %d purchase(s), %d expense(s).',
            $waiting['sale'], $waiting['purchase'], $waiting['expense']));

        $posted = LedgerPoster::sweep((int) $this->option('limit'));

        $this->info(sprintf('Posted %d sale(s), %d purchase(s), %d expense(s).',
            $posted['sale'], $posted['purchase'], $posted['expense']));

        if ($posted['failed'] > 0) {
            $this->error($posted['failed'] . ' document(s) could not be posted; see the log.');
        }

        $left = LedgerPoster::pendingCount();
        if ($left['total'] > 0) {
            $this->comment($left['total'] . ' still waiting -- run again to continue.');
        }

        return 0;
    }
}
