<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\DeliveryDetail;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\utils\DocumentSequence;

class DeliveryController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Sale::class);

        $perPage = \App\utils\ListLimit::of($request->limit, 10);
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;

        $q = \DB::table('deliveries as d')
            ->join('sales as s', 's.id', '=', 'd.sale_id')
            ->leftJoin('clients as c', 'c.id', '=', 'd.client_id')
            ->whereNull('d.deleted_at')
            // A query builder skips the model's branch scope, so it is applied
            // here: staff tied to a branch see only its deliveries.
            ->when(\App\utils\WarehouseAccess::isRestricted(), function ($q) {
                return $q->whereIn('s.warehouse_id', \App\utils\WarehouseAccess::allowedIds());
            })
            ->when($request->filled('status'), function ($q) use ($request) {
                return $q->where('d.status', $request->status);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                return $q->where(function ($w) use ($request) {
                    $w->where('d.Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('d.tracking_number', 'LIKE', "%{$request->search}%")
                        ->orWhere('s.Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('c.name', 'LIKE', "%{$request->search}%");
                });
            });

        $totalRows = $q->count();

        $rows = $q->orderByDesc('d.id')->offset($offSet)->limit($perPage)
            ->select(
                'd.id', 'd.Ref', 'd.date', 'd.status', 'd.courier', 'd.tracking_number',
                'd.dispatched_at', 'd.delivered_at', 'd.received_by', 'd.failure_reason',
                'd.sale_id', 's.Ref as sale_ref', 's.GrandTotal', 'c.name as client'
            )->get();

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'id' => $r->id,
                'Ref' => $r->Ref,
                'date' => $r->date,
                'status' => $r->status,
                'courier' => $r->courier,
                'tracking_number' => $r->tracking_number,
                'dispatched_at' => $r->dispatched_at,
                'delivered_at' => $r->delivered_at,
                'received_by' => $r->received_by,
                'failure_reason' => $r->failure_reason,
                'sale_id' => $r->sale_id,
                'sale_ref' => $r->sale_ref,
                'GrandTotal' => (float) $r->GrandTotal,
                'client' => $r->client,
                // What the screen may offer next, straight from the state machine.
                'next_states' => Delivery::transitions()[$r->status] ?? [],
            ];
        }

        return response()->json([
            'deliveries' => $data,
            'totalRows' => $totalRows,
            'statuses' => array_keys(Delivery::transitions()),
        ]);
    }

    /** Completed sales that have no delivery yet -- what can still be shipped. */
    public function undelivered(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Sale::class);

        $rows = \DB::table('sales as s')
            ->leftJoin('clients as c', 'c.id', '=', 's.client_id')
            ->leftJoin('deliveries as d', function ($j) {
                $j->on('d.sale_id', '=', 's.id')->whereNull('d.deleted_at');
            })
            ->whereNull('s.deleted_at')
            ->where('s.statut', 'completed')
            ->whereNull('d.id')
            ->orderByDesc('s.id')->limit(200)
            ->select('s.id', 's.Ref', 's.date', 's.GrandTotal', 's.warehouse_id',
                's.client_id', 'c.name as client', 'c.adresse')
            ->get();

        return response()->json(['sales' => $rows]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Sale::class);

        $request->validate([
            'sale_id' => 'required|integer|exists:sales,id',
            'date' => 'required|date',
        ]);

        $sale = Sale::findOrFail($request->sale_id);

        if ($sale->statut !== 'completed') {
            throw ValidationException::withMessages([
                'sale_id' => ['Only a completed sale can be dispatched.'],
            ]);
        }

        $exists = Delivery::where('sale_id', $request->sale_id)
            ->where('deleted_at', '=', null)->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'sale_id' => ['That sale already has a delivery against it.'],
            ]);
        }

        \DB::transaction(function () use ($request, $sale) {
            $delivery = Delivery::create([
                'Ref' => $this->nextRef(),
                'sale_id' => $sale->id,
                'client_id' => $sale->client_id,
                'warehouse_id' => $sale->warehouse_id,
                'date' => $request->date,
                'status' => 'pending',
                'courier' => $request['courier'],
                'tracking_number' => $request['tracking_number'],
                'shipping_address' => $request['shipping_address'],
                'notes' => $request['notes'],
                'user_id' => Auth::user()->id,
            ]);

            // Default to shipping the whole sale; quantities can be edited later
            // for a partial shipment.
            $lines = \DB::table('sale_details')->where('sale_id', $sale->id)->get();
            foreach ($lines as $l) {
                DeliveryDetail::create([
                    'delivery_id' => $delivery->id,
                    'sale_detail_id' => $l->id,
                    'product_id' => $l->product_id,
                    'product_variant_id' => $l->product_variant_id,
                    'quantity' => $l->quantity,
                ]);
            }
        });

        return response()->json(['success' => true]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Sale::class);

        $delivery = Delivery::findOrFail($id);
        $sale = Sale::find($delivery->sale_id);

        $details = \DB::table('delivery_details as dd')
            ->join('products as p', 'p.id', '=', 'dd.product_id')
            ->where('dd.delivery_id', $id)
            ->select('dd.id', 'dd.quantity', 'p.name as product', 'p.code')
            ->get();

        return response()->json([
            'delivery' => $delivery,
            'sale' => $sale,
            'details' => $details,
            'next_states' => Delivery::transitions()[$delivery->status] ?? [],
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Sale::class);

        Delivery::whereId($id)->update([
            'courier' => $request['courier'],
            'tracking_number' => $request['tracking_number'],
            'shipping_address' => $request['shipping_address'],
            'notes' => $request['notes'],
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Move a delivery to its next state.
     *
     * Only transitions the state machine allows are accepted, so a delivery
     * cannot jump from pending straight to returned, and a delivered one
     * cannot quietly go back to pending.
     */
    public function transition(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Sale::class);

        $request->validate(['status' => 'required|string']);

        $delivery = Delivery::findOrFail($id);
        $allowed = Delivery::transitions()[$delivery->status] ?? [];

        if (! in_array($request->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => [
                    'A delivery that is "' . $delivery->status . '" cannot become "' . $request->status . '". '
                    . (empty($allowed)
                        ? 'It is in a final state.'
                        : 'Allowed next: ' . implode(', ', $allowed) . '.'),
                ],
            ]);
        }

        $data = ['status' => $request->status];

        if ($request->status === 'dispatched') {
            $data['dispatched_at'] = now();
            if ($request['courier']) {
                $data['courier'] = $request['courier'];
            }
            if ($request['tracking_number']) {
                $data['tracking_number'] = $request['tracking_number'];
            }
        }

        if ($request->status === 'delivered') {
            // Proof of delivery is the point of the state; require a name.
            if (! $request['received_by']) {
                throw ValidationException::withMessages([
                    'received_by' => ['Record who received the goods.'],
                ]);
            }
            $data['delivered_at'] = now();
            $data['received_by'] = $request['received_by'];
        }

        if ($request->status === 'failed') {
            if (! $request['failure_reason']) {
                throw ValidationException::withMessages([
                    'failure_reason' => ['Say why the delivery failed.'],
                ]);
            }
            $data['failure_reason'] = $request['failure_reason'];
        }

        Delivery::whereId($id)->update($data);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Sale::class);

        $delivery = Delivery::findOrFail($id);

        if (in_array($delivery->status, ['delivered', 'returned'], true)) {
            throw ValidationException::withMessages([
                'delivery' => ['A delivery that has already been completed cannot be deleted.'],
            ]);
        }

        Delivery::whereId($id)->update(['deleted_at' => now()]);

        return response()->json(['success' => true]);
    }

    private function nextRef()
    {
        // Was: read the last row, split the reference, add one -- which handed
        // two simultaneous callers the same number. DocumentSequence claims it
        // atomically, and a unique index on Ref refuses a duplicate outright.
        return DocumentSequence::next('deliveries', 'DLV-', 6, 1);
    }
}
