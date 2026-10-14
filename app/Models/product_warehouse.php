<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class product_warehouse extends Model
{
    use SoftDeletes;

    protected $table = 'product_warehouse';

    /** The balance actually on the row when this write was re-based (see booted). */
    public $ledgerBefore = null;

    protected $fillable = [
        'product_id', 'warehouse_id', 'product_variant_id', 'qte',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'warehouse_id' => 'integer',
        'product_variant_id' => 'integer',
        'qte' => 'float',
    ];

    protected static function booted()
    {
        static::saving(function (self $stock) {
            // A service (labour, repairs, delivery) is sold, never stocked: its
            // shelf count stays at zero whatever path tries to move it, so it
            // is never "out of stock" and leaves no stock movement.
            if (\App\utils\ProductKind::isService($stock->product_id)) {
                $stock->qte = 0;

                return;
            }

            // Every caller reads the row, does arithmetic, and saves the result. Two
            // at once both read the same number and the second overwrites the first
            // (DATATEST K2: lost updates in 19 of 20 races, oversold transfers). So
            // the write is re-based here: re-read the row under a lock and apply
            // only this request's change to what is really there now. Inside the
            // caller's transaction the lock holds until commit.
            if ($stock->exists && $stock->isDirty('qte') && ! \App\utils\BatchService::$syncing) {
                $original = (float) $stock->getOriginal('qte');
                $delta = (float) $stock->qte - $original;
                $fresh = \Illuminate\Support\Facades\DB::table('product_warehouse')
                    ->where('id', $stock->id)->lockForUpdate()->value('qte');
                if ($fresh !== null) {
                    $stock->ledgerBefore = (float) $fresh;
                    $stock->qte = (float) $fresh + $delta;
                }
            }

            // Small floating-point noise is normalized to zero.
            if (abs((float) $stock->qte) < 0.000001) {
                $stock->qte = 0;
            }

            if ((float) $stock->qte < 0) {
                throw ValidationException::withMessages([
                    'stock' => ['Insufficient stock for the selected product and warehouse.'],
                ]);
            }

            $stock->guardBatchTrackedProduct();
        });

        // Every change to stock leaves a movement, whatever changed it.
        //
        // Hanging this off the model rather than off each controller is the whole
        // point: a path that forgets to log is exactly the hole this closes. The
        // delta comes from the value the row held before the write, so a movement
        // is recorded even when the code that moved the stock knows nothing about
        // ledgers at all.
        static::saved(function (self $stock) {
            if (! $stock->wasRecentlyCreated && ! $stock->wasChanged('qte')) {
                return;
            }

            $before = $stock->wasRecentlyCreated
                ? 0.0
                : ($stock->ledgerBefore ?? (float) ($stock->getOriginal('qte') ?? 0));
            $stock->ledgerBefore = null;
            $after = (float) $stock->qte;

            \App\utils\StockLedger::record(
                $stock->product_id,
                $stock->warehouse_id,
                $stock->product_variant_id,
                $after - $before,
                $after
            );
        });
    }

    /**
     * For a batch-tracked product this total is derived from its lots, so it
     * may only be written by BatchService. Any other code path changing it
     * would silently desynchronise the two layers, which is far worse than
     * being told the operation is not supported yet.
     *
     * A brand-new row at zero is allowed, because creating a product seeds an
     * empty stock row per warehouse before any lot exists.
     */
    private function guardBatchTrackedProduct()
    {
        if (\App\utils\BatchService::$syncing) {
            return;
        }

        if (! $this->isDirty('qte')) {
            return;
        }

        if (! $this->exists && (float) $this->qte === 0.0) {
            return;
        }

        if (! \App\utils\BatchService::isTracked($this->product_id)) {
            return;
        }

        throw ValidationException::withMessages([
            'batch' => [\App\utils\BatchService::untrackedPathMessage($this->product_id)],
        ]);
    }

    /**
     * The stock row for a product in a warehouse, made if it is not there.
     *
     * Every controller that moves stock used to read this row and then guard
     * its write with `if ($row)`. When the row was missing the write was
     * skipped in silence -- and for a transfer, whose debit had already been
     * taken, that quietly destroyed the goods. A product that has never been
     * held in a warehouse simply has no row yet; that is a gap in the records,
     * not a reason to lose stock, so the row is created at zero and the
     * movement is recorded against it like any other.
     *
     * Created rows always start at zero and are then written to normally, so
     * the saved() hook above still sees the change and logs it.
     */
    public static function ensure($productId, $warehouseId, $variantId = null)
    {
        $row = static::where('deleted_at', '=', null)
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variantId)
            ->first();

        if ($row) {
            return $row;
        }

        return static::create([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'product_variant_id' => $variantId,
            'qte' => 0,
        ]);
    }

    // Stock levels name every warehouse, even ones the viewer is not on:
    // seeing another branch's stock is how a stockman asks for a transfer.
    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse')
            ->withoutGlobalScope(\App\Models\Scopes\UserWarehouseScope::class);
    }

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }

    public function productVariant()
    {
        return $this->belongsTo('App\Models\ProductVariant');
    }
}
