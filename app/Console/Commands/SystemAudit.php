<?php

namespace App\Console\Commands;

use App\utils\SystemAudit as Audit;
use Illuminate\Console\Command;

class SystemAudit extends Command
{
    protected $signature = 'system:audit
                            {--fix : Bring the shelf back into line with the movements that explain it}';

    protected $description = 'Check the data for inconsistencies: stock, journals, voids, the ledger and the clock';

    public function handle()
    {
        $result = Audit::run();

        foreach ($result['checks'] as $check) {
            $mark = $check['passed'] ? '<fg=green>  ok  </>' : '<fg=red>FAIL  </>';
            $this->line(sprintf('%s %-32s %s', $mark, $check['name'], $check['detail']));

            foreach ($check['rows'] as $row) {
                $this->line('         <fg=yellow>' . $row . '</>');
            }
        }

        if ($result['notes']) {
            $this->line('');
            $this->line('Worth knowing, not broken:');
            foreach ($result['notes'] as $note) {
                $this->line('  - ' . $note);
            }
        }

        $this->line('');

        if ($result['failures'] === 0) {
            $this->info(sprintf('All %d check(s) passed.', count($result['checks'])));

            if ($this->option('fix')) {
                $this->line('Nothing to repair.');
            }

            return 0;
        }

        $this->error(sprintf('%d of %d check(s) failed.',
            $result['failures'], count($result['checks'])));

        if (! $this->option('fix')) {
            // Only the stock drift can be put right mechanically. Everything
            // else needs somebody to decide what the right answer is, so it is
            // reported rather than guessed at.
            $this->line('Run with <fg=cyan>--fix</> to reconcile stock against its movements.');

            return 1;
        }

        $this->line('');

        // Missing rows first: reconciling drift reads the rows that exist, so
        // a product with no row for a warehouse would otherwise be skipped and
        // still be skipped on the next run.
        $created = Audit::createMissingStockRows();
        if ($created > 0) {
            $this->info(sprintf('%d missing stock row(s) created at zero.', $created));
        }

        $fixed = Audit::repairStockDrift();
        $this->info(sprintf('%d stock row(s) reconciled against their movements.', $fixed));

        $after = Audit::run();
        if ($after['failures'] === 0) {
            $this->info('All checks pass now.');

            return 0;
        }

        $this->error(sprintf('%d check(s) still failing — these need a person.',
            $after['failures']));

        return 1;
    }
}
