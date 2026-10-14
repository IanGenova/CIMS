<?php

namespace App\Console\Commands;

use App\utils\MarketplaceSyncService;
use Illuminate\Console\Command;

class MarketplaceSync extends Command
{
    protected $signature = 'marketplace:sync
                            {--account= : Only this account}
                            {--orders-only : Pull orders and do not push stock}
                            {--stock-only : Push stock and do not pull orders}';

    protected $description = 'Pull Shopee and Lazada orders into CIMS, then push stock levels back';

    public function handle()
    {
        $accounts = MarketplaceSyncService::activeAccounts();

        if ($this->option('account')) {
            $accounts = $accounts->where('id', (int) $this->option('account'));
        }

        if ($accounts->isEmpty()) {
            $this->line('No active marketplace accounts.');

            return 0;
        }

        $problems = 0;

        foreach ($accounts as $account) {
            $this->line(sprintf('<fg=cyan>%s</> (%s)', $account->name, $account->channel));

            if (! $this->option('stock-only')) {
                $result = MarketplaceSyncService::importOrders($account->id);

                if (isset($result['error'])) {
                    $this->error('  orders: ' . $result['error']);
                    $problems++;
                } else {
                    $this->line(sprintf('  orders: %d imported, %d already seen, %d unmapped, %d cancelled, %d failed',
                        $result['imported'], $result['already'], $result['unmapped'],
                        $result['cancelled'] ?? 0, $result['failed'] ?? 0));

                    // An unmapped order is not an error but it is a job for a
                    // person, so it is said loudly rather than counted quietly.
                    if (($result['unmapped'] ?? 0) > 0) {
                        $this->warn('  ' . $result['unmapped'] . ' order(s) are waiting for a product mapping.');
                    }
                    if (($result['failed'] ?? 0) > 0) {
                        $problems++;
                    }
                }
            }

            if (! $this->option('orders-only')) {
                $result = MarketplaceSyncService::pushStock($account->id);

                if (isset($result['error'])) {
                    $this->error('  stock: ' . $result['error']);
                    $problems++;
                } else {
                    $this->line(sprintf('  stock: %d pushed, %d unchanged, %d failed',
                        $result['pushed'], $result['skipped'], $result['failed']));
                    if ($result['failed'] > 0) {
                        $problems++;
                    }
                }
            }
        }

        return $problems > 0 ? 1 : 0;
    }
}
