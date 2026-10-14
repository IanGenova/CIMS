<?php

namespace App\utils;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * A notice for each activity, addressed to the people who have to act on it.
 *
 * Called from inside the transaction that does the work, so a notice exists
 * exactly when the thing it describes does: a purchase that fails to save
 * leaves no "needs approval" behind. The person who did it is recorded as the
 * actor and never told about their own action (NotificationService::forUser).
 *
 * WHO HEARS WHAT
 *   purchases_approve   a purchase is waiting for a signature
 *   goods_receipt_add   an order is coming and can be booked in
 *   (the buyer)         their order was approved, rejected, or delivered
 *   record_view         managers: voids, till variances, write-offs, counts,
 *                       returns -- the things an owner wants to know about
 *   transfer_view       stock is on its way between warehouses
 *   products_edit       marketplace orders need a product mapped, or a shop
 *                       needs reconnecting
 *
 * See tests/Feature/ActivityNotificationsTest.php.
 */
class ActivityNotifier
{
    const APPROVERS = 'purchases_approve';
    const RECEIVERS = 'goods_receipt_add';
    const MANAGERS = 'record_view';
    const TRANSFERS = 'transfer_view';
    const CATALOGUE = 'products_edit';

    // ------------------------------------------------------------ purchasing

    /** A purchase was raised: either it needs a signature, or it is coming. */
    public static function purchaseRaised($purchaseId)
    {
        $p = self::purchase($purchaseId);
        if (! $p) {
            return;
        }

        if ($p->approval_status === 'pending') {
            NotificationService::broadcast(self::APPROVERS, 'purchase_approval',
                $p->Ref . ' needs approval',
                self::money($p->GrandTotal) . ' from ' . $p->supplier . ', raised by ' . self::who($p->user_id) . '.',
                '/app/purchases/approvals', 'warning', 'i-Check', self::actor());

            return;
        }

        if ($p->statut === 'ordered') {
            self::onOrder($p, $p->Ref . ' is on order');
        }
    }

    public static function purchaseApproved($purchaseId)
    {
        $p = self::purchase($purchaseId);
        if (! $p) {
            return;
        }

        NotificationService::toUser($p->user_id, 'purchase_approved',
            'Your ' . $p->Ref . ' was approved',
            'Approved by ' . self::who(self::actor()) . '. ' . self::money($p->GrandTotal) . ' from ' . $p->supplier . '.',
            '/app/purchases/detail/' . $p->id, 'success', 'i-Yes', self::actor());

        if ($p->statut === 'ordered') {
            self::onOrder($p, $p->Ref . ' is approved and on order');
        }
    }

    public static function purchaseRejected($purchaseId)
    {
        $p = self::purchase($purchaseId);
        if (! $p) {
            return;
        }

        NotificationService::toUser($p->user_id, 'purchase_rejected',
            'Your ' . $p->Ref . ' was rejected',
            'Rejected by ' . self::who(self::actor()) . ': ' . $p->rejection_reason,
            '/app/purchases/detail/' . $p->id, 'danger', 'i-Close', self::actor());
    }

    /** Stock was booked in against a purchase. */
    public static function goodsReceived($purchaseId)
    {
        $p = self::purchase($purchaseId);
        if (! $p) {
            return;
        }

        $outstanding = DB::table('purchase_details')->where('purchase_id', $p->id)
            ->whereRaw('quantity - received_qte > 0.000001')->count();

        NotificationService::toUser($p->user_id, 'goods_received',
            $p->Ref . ' delivery booked in',
            'Booked in by ' . self::who(self::actor()) . ' at ' . $p->warehouse . '. '
                . ($outstanding ? $outstanding . ' line(s) still outstanding.' : 'Everything ordered has arrived.'),
            '/app/purchases/detail/' . $p->id, 'success', 'i-Receipt', self::actor());
    }

    private static function onOrder($p, $title)
    {
        NotificationService::broadcast(self::RECEIVERS, 'purchase_on_order', $title,
            'From ' . $p->supplier . ' to ' . $p->warehouse . '. Book it in when it arrives.',
            '/app/goods_receipts/store', 'info', 'i-Truck', self::actor(), $p->warehouse_id);
    }

    // ------------------------------------------------------------ the counter

    public static function saleVoided($saleId)
    {
        $s = DB::table('sales')->where('id', $saleId)->first();
        if (! $s) {
            return;
        }

        NotificationService::broadcast(self::MANAGERS, 'sale_voided',
            $s->Ref . ' was voided',
            self::money($s->GrandTotal) . ', voided by ' . self::who(self::actor())
                . ($s->void_reason ? ': ' . $s->void_reason : '.'),
            '/app/sales/detail/' . $s->id, 'warning', 'i-Close', self::actor(), $s->warehouse_id);
    }

    /** Only a drawer that did not balance is news. */
    public static function tillClosed($sessionId)
    {
        $t = DB::table('till_sessions')->where('id', $sessionId)->first();
        if (! $t || $t->variance === null || abs((float) $t->variance) < 0.005) {
            return;
        }

        $variance = (float) $t->variance;
        $short = $variance < 0;

        NotificationService::broadcast(self::MANAGERS, 'till_variance',
            'Till closed ' . self::money(abs($variance)) . ($short ? ' short' : ' over'),
            'Closed by ' . self::who($t->user_id) . ': expected ' . self::money($t->expected_cash)
                . ', counted ' . self::money($t->counted_cash) . '.',
            '/app/reports/till_sessions', $short ? 'danger' : 'warning', 'i-Money-2', self::actor(), $t->warehouse_id);
    }

    public static function saleReturned($returnId)
    {
        $r = DB::table('sale_returns')->where('id', $returnId)->first();
        if (! $r) {
            return;
        }

        NotificationService::broadcast(self::MANAGERS, 'sale_return',
            'Return ' . $r->Ref . ' taken in',
            self::money($r->GrandTotal) . ', by ' . self::who($r->user_id) . '.',
            '/app/sale_return/detail/' . $r->id, 'info', 'i-Left', self::actor(), $r->warehouse_id);
    }

    // ------------------------------------------------------------ the stockroom

    public static function stockAdjusted($adjustmentId)
    {
        $a = DB::table('adjustments as a')->leftJoin('warehouses as w', 'w.id', '=', 'a.warehouse_id')
            ->where('a.id', $adjustmentId)->select('a.*', 'w.name as warehouse')->first();
        if (! $a) {
            return;
        }

        $lines = DB::table('adjustment_details')->where('adjustment_id', $a->id)->get();
        $out = $lines->where('type', 'sub')->count();
        $in = $lines->where('type', 'add')->count();
        $parts = array_filter([$out ? $out . ' line(s) taken off' : null, $in ? $in . ' line(s) added' : null]);

        NotificationService::broadcast(self::MANAGERS, 'stock_adjusted',
            'Stock adjusted: ' . $a->Ref,
            ucfirst(implode(', ', $parts) ?: 'no lines') . ' at ' . $a->warehouse . ', by ' . self::who($a->user_id) . '.',
            '/app/adjustments/list', $out ? 'warning' : 'info', 'i-Edit-Map', self::actor(), $a->warehouse_id);
    }

    public static function stockTransferred($transferId)
    {
        $t = DB::table('transfers as t')
            ->leftJoin('warehouses as f', 'f.id', '=', 't.from_warehouse_id')
            ->leftJoin('warehouses as d', 'd.id', '=', 't.to_warehouse_id')
            ->where('t.id', $transferId)->select('t.*', 'f.name as from_name', 'd.name as to_name')->first();
        if (! $t) {
            return;
        }

        NotificationService::broadcast(self::TRANSFERS, 'stock_transfer',
            'Transfer ' . $t->Ref . ': ' . $t->from_name . ' to ' . $t->to_name,
            (int) $t->items . ' line(s), ' . $t->statut . ', by ' . self::who($t->user_id) . '.',
            '/app/transfers/list', 'info', 'i-Jeep', self::actor());
    }

    /** Only a count that changed the shelf figures is news. */
    public static function stockCountCompleted($countId, $adjustedLines)
    {
        if ((int) $adjustedLines < 1) {
            return;
        }

        $c = DB::table('stock_counts as c')->leftJoin('warehouses as w', 'w.id', '=', 'c.warehouse_id')
            ->where('c.id', $countId)->select('c.*', 'w.name as warehouse')->first();
        if (! $c) {
            return;
        }

        NotificationService::broadcast(self::MANAGERS, 'stock_count',
            'Stock count ' . $c->Ref . ' changed ' . (int) $adjustedLines . ' line(s)',
            'Counted at ' . $c->warehouse . ' by ' . self::who($c->user_id) . '; the shelf figures now match the count.',
            '/app/stock_counts/sheet/' . $c->id, 'warning', 'i-Check', self::actor(), $c->warehouse_id);
    }

    // ------------------------------------------------------------ marketplace
    //
    // These come from Shopee or Lazada, not from a person here, so there is no
    // actor to leave out: whoever pressed "Sync now" needs to know too.

    public static function marketplaceOrdersWaiting($accountId, $count)
    {
        if ((int) $count < 1) {
            return;
        }

        $a = DB::table('marketplace_accounts')->where('id', $accountId)->first();
        NotificationService::broadcast(self::CATALOGUE, 'marketplace_waiting',
            (int) $count . ' ' . ($a ? ucfirst($a->channel) : 'marketplace') . ' order(s) waiting',
            'A SKU on the order is not mapped to a product yet. Map it, then put the order through.',
            '/app/products/marketplace', 'warning', 'i-Shop-4', null);
    }

    public static function marketplaceReconnect($accountId, $message)
    {
        $a = DB::table('marketplace_accounts')->where('id', $accountId)->first();
        NotificationService::broadcast(self::CATALOGUE, 'marketplace_reconnect',
            ($a ? $a->name : 'A marketplace shop') . ' needs reconnecting',
            $message, '/app/products/marketplace', 'danger', 'i-Shop-4', null);
    }

    // ------------------------------------------------------------ helpers

    private static function purchase($id)
    {
        return DB::table('purchases as p')
            ->leftJoin('providers as s', 's.id', '=', 'p.provider_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'p.warehouse_id')
            ->where('p.id', $id)
            ->select('p.*', 's.name as supplier', 'w.name as warehouse')
            ->first();
    }

    private static function actor()
    {
        return Auth::check() ? Auth::user()->id : null;
    }

    private static function who($userId)
    {
        if (! $userId) {
            return 'the system';
        }
        $u = DB::table('users')->where('id', $userId)->first(['firstname', 'lastname', 'username']);

        return $u ? trim($u->firstname . ' ' . $u->lastname) ?: $u->username : 'someone';
    }

    private static function money($amount)
    {
        static $symbol = null;
        if ($symbol === null) {
            $symbol = DB::table('settings as s')->join('currencies as c', 'c.id', '=', 's.currency_id')
                ->whereNull('s.deleted_at')->value('c.symbol') ?: '₱';
        }

        return $symbol . number_format((float) $amount, 2);
    }
}
