<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;

/**
 * In-app notifications.
 *
 * The bell in the header had nothing behind it: the badge was hard-coded to
 * the literal "1" and the only destination it could ever offer was the stock
 * alert report. Notifications are now records, addressed either to one user or
 * broadcast to everyone holding a given permission.
 */
class NotificationService
{
    /** Raised by generateAlerts(); each replaces its earlier versions. */
    const STANDING_ALERTS = ['stock_alert', 'expiry', 'approval', 'credit'];

    /** Send to one person. */
    public static function toUser($userId, $type, $title, $body = null, $link = null, $level = 'info', $icon = null, $actorId = null)
    {
        $id = DB::table('app_notifications')->insertGetId([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'level' => $level,
            'icon' => $icon,
            'actor_id' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Also to their phone, or their computer with the browser closed.
        WebPush::queue($id);

        return $id;
    }

    /**
     * Send to everyone holding a permission. Stored once; read state is per
     * user, so one row does not get marked read for the whole company.
     */
    public static function broadcast($permission, $type, $title, $body = null, $link = null, $level = 'info', $icon = null, $actorId = null, $warehouseId = null)
    {
        $id = DB::table('app_notifications')->insertGetId([
            'user_id' => null,
            // About one warehouse: only people who may use it are told (K96).
            'warehouse_id' => $warehouseId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'level' => $level,
            'icon' => $icon,
            'permission' => $permission,
            // Whoever caused it does not get told about it.
            'actor_id' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        WebPush::queue($id);

        return $id;
    }

    /** The warehouses a user may use, or null for all of them. */
    private static function warehousesOf($userId)
    {
        $all = DB::table('users')->where('id', $userId)->value('all_warehouses');
        if ($all === null || (int) $all === 1) {
            return null;
        }

        return DB::table('user_warehouse')->where('user_id', $userId)->pluck('warehouse_id')->map(function ($id) {
            return (int) $id;
        })->all();
    }

    /** What this user should see, newest first. */
    public static function forUser($userId, $permissions = [], $limit = 20, $unreadOnly = false)
    {
        $rows = DB::table('app_notifications as n')
            ->leftJoin('notification_reads as r', function ($j) use ($userId) {
                $j->on('r.notification_id', '=', 'n.id')->where('r.user_id', '=', $userId);
            })
            ->where(function ($q) use ($userId, $permissions) {
                $q->where('n.user_id', $userId)
                    ->orWhere(function ($b) use ($permissions) {
                        $b->whereNull('n.user_id')
                            ->where(function ($p) use ($permissions) {
                                $p->whereNull('n.permission');
                                if (! empty($permissions)) {
                                    $p->orWhereIn('n.permission', $permissions);
                                }
                            });
                    });
            })
            // Never a notice about something this user did themselves.
            ->where(function ($q) use ($userId) {
                $q->whereNull('n.actor_id')->orWhere('n.actor_id', '!=', $userId);
            })
            // Nor one about a warehouse they cannot open (DATATEST K96).
            ->when(self::warehousesOf($userId) !== null, function ($q) use ($userId) {
                $q->where(function ($w) use ($userId) {
                    $w->whereNull('n.warehouse_id')->orWhereIn('n.warehouse_id', self::warehousesOf($userId));
                });
            })
            // A standing alert (low stock, expiry...) describes the situation
            // now: only its latest version is shown, or yesterday's copies
            // bury the bell (NotificationServiceAlertsTest).
            ->where(function ($q) {
                $q->whereNotIn('n.type', self::STANDING_ALERTS)
                    ->orWhereNotExists(function ($newer) {
                        $newer->select(DB::raw(1))->from('app_notifications as later')
                            ->whereColumn('later.type', 'n.type')
                            ->whereColumn('later.id', '>', 'n.id');
                    });
            })
            ->when($unreadOnly, function ($q) {
                // A direct notice is read via its own column; a broadcast via the pivot.
                return $q->whereNull('n.read_at')->whereNull('r.read_at');
            })
            ->orderByDesc('n.id')->limit($limit)
            ->select('n.*', 'r.read_at as broadcast_read_at')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $isRead = $r->user_id ? $r->read_at !== null : $r->broadcast_read_at !== null;
            $out[] = [
                'id' => $r->id,
                'type' => $r->type,
                'title' => $r->title,
                'body' => $r->body,
                'link' => $r->link,
                'level' => $r->level,
                'icon' => $r->icon,
                'is_broadcast' => $r->user_id === null,
                'is_read' => $isRead,
                'created_at' => $r->created_at,
            ];
        }

        return $out;
    }

    /** The number the badge should actually show. */
    public static function unreadCount($userId, $permissions = [])
    {
        return count(self::forUser($userId, $permissions, 100, true));
    }

    public static function markRead($notificationId, $userId)
    {
        $n = DB::table('app_notifications')->where('id', $notificationId)->first();
        if (! $n) {
            return;
        }

        if ($n->user_id) {
            DB::table('app_notifications')->where('id', $notificationId)
                ->where('user_id', $userId)
                ->update(['read_at' => now(), 'updated_at' => now()]);

            return;
        }

        // Broadcast: read state belongs to the reader, not the notice.
        $existing = DB::table('notification_reads')
            ->where('notification_id', $notificationId)->where('user_id', $userId)->first();

        if ($existing) {
            DB::table('notification_reads')->where('id', $existing->id)
                ->update(['read_at' => now()]);
        } else {
            DB::table('notification_reads')->insert([
                'notification_id' => $notificationId,
                'user_id' => $userId,
                'read_at' => now(),
            ]);
        }
    }

    public static function markAllRead($userId, $permissions = [])
    {
        foreach (self::forUser($userId, $permissions, 200, true) as $n) {
            self::markRead($n['id'], $userId);
        }
    }

    /**
     * Raise notices for anything that currently needs a person's attention.
     * Deduplicated by title within the day, so running it repeatedly does not
     * bury the bell in copies of the same warning.
     */
    public static function generateAlerts()
    {
        $created = 0;

        // Stock below its alert threshold.
        // The same shelves the badge and the list count (DATATEST K83).
        $low = LowStock::shelves()->count();

        if ($low > 0) {
            $created += self::once(
                'stock_alert',
                $low . ' product(s) at or below their alert level',
                'Reports_quantity_alerts',
                '/app/reports/quantity_alerts',
                'warning'
            );
        }

        // Lots already expired, if batch tracking is in use.
        if (\Schema::hasTable('batch_stock')) {
            $expired = DB::table('batch_stock as bs')
                ->join('product_batches as b', 'b.id', '=', 'bs.product_batch_id')
                ->whereNull('b.deleted_at')
                ->where('bs.qte', '>', 0)
                ->whereNotNull('b.expiry_date')
                ->where('b.expiry_date', '<', date('Y-m-d'))
                ->count();

            if ($expired > 0) {
                $created += self::once(
                    'expiry',
                    $expired . ' lot(s) have expired and are still in stock',
                    'Reports_quantity_alerts',
                    '/app/reports/expiry_report',
                    'danger'
                );
            }
        }

        // Purchases waiting on a second signature.
        if (\Schema::hasColumn('purchases', 'approval_status')) {
            $pending = DB::table('purchases')->whereNull('deleted_at')
                ->where('approval_status', 'pending')->count();

            if ($pending > 0) {
                $created += self::once(
                    'approval',
                    $pending . ' purchase(s) awaiting approval',
                    'purchases_approve',
                    '/app/purchases/approvals',
                    'info'
                );
            }
        }

        // Customers past their credit ceiling.
        if (\Schema::hasColumn('clients', 'credit_limit')) {
            $over = 0;
            $clients = DB::table('clients')->whereNull('deleted_at')
                ->where('credit_limit', '>', 0)->get(['id', 'credit_limit']);
            foreach ($clients as $c) {
                if (CreditService::balanceFor($c->id)['balance'] > (float) $c->credit_limit) {
                    $over++;
                }
            }
            if ($over > 0) {
                $created += self::once(
                    'credit',
                    $over . ' customer(s) are over their credit limit',
                    'Customers_view',
                    '/app/reports/customer_credit',
                    'danger'
                );
            }
        }

        return $created;
    }

    /** Broadcast once per title per day. */
    private static function once($type, $title, $permission, $link, $level)
    {
        $exists = DB::table('app_notifications')
            ->where('type', $type)
            ->where('title', $title)
            ->where('created_at', '>=', date('Y-m-d 00:00:00'))
            ->exists();

        if ($exists) {
            return 0;
        }

        self::broadcast($permission, $type, $title, null, $link, $level);

        return 1;
    }
}
