<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Warehouse;
use App\utils\TillService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The drawer: open it, move cash through it, read it, close it against a count.
 */
class TillController extends Controller
{
    /**
     * What this cashier has open right now, with the reading so far.
     */
    public function current(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $warehouseId = $request->warehouse_id;
        if (! $warehouseId) {
            return response()->json([
                'session' => null,
                'required' => TillService::isRequired(),
                'warehouses' => $this->warehouses(),
            ]);
        }

        $session = TillService::currentFor(Auth::user()->id, $warehouseId, $request->terminal);

        return response()->json([
            'session' => $session,
            'reading' => $session ? TillService::reading($session->id) : null,
            'required' => TillService::isRequired(),
            'warehouses' => $this->warehouses(),
        ]);
    }

    public function open(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'opening_float' => 'required|numeric|min:0',
            'terminal' => 'nullable|string|max:64',
            'notes' => 'nullable|string|max:2000',
        ]);

        $result = TillService::open(
            $request->warehouse_id,
            $request->opening_float,
            $request->terminal ?: 'MAIN',
            $request->notes
        );

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error'] === 'user_has_open'
                    ? 'You already have a till open at this warehouse (terminal ' . $result['session']->terminal . '). Close it before opening another.'
                    : 'This drawer already has a session open. Close it before opening another.',
                'session' => $result['session'] ?? null,
            ], 409);
        }

        return response()->json([
            'session' => $result['session'],
            'reading' => TillService::reading($result['session']->id),
        ]);
    }

    /** Cash in or out, with a reason. */
    public function cash(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $request->validate([
            'till_session_id' => 'required|integer|exists:till_sessions,id',
            'type' => 'required|string|in:cash_in,cash_out',
            'amount' => 'required|numeric|gt:0',
            'reason' => 'nullable|string|max:255',
        ]);

        $result = TillService::move(
            $request->till_session_id, $request->type, $request->amount, $request->reason
        );

        if (isset($result['error'])) {
            $messages = [
                'not_open' => 'That session is closed. Cash cannot be moved through a closed drawer.',
                'bad_amount' => 'The amount has to be more than zero, in whole centavos.',
                'bad_type' => 'That is not a kind of cash movement.',
                'not_enough_cash' => 'The drawer should only hold ' . number_format($result['expected'] ?? 0, 2) . '; a payout cannot take out more.',
            ];

            return response()->json(['message' => $messages[$result['error']]], 422);
        }

        return response()->json([
            'movement' => $result['movement'],
            'reading' => TillService::reading($request->till_session_id),
        ]);
    }

    /**
     * The X-reading: the shift so far, without closing anything.
     */
    public function reading(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $reading = TillService::reading($id);
        if (! $reading) {
            return response()->json(['message' => 'No such till session.'], 404);
        }
        \App\utils\WarehouseAccess::assertAllows(DB::table('till_sessions')->where('id', $id)->value('warehouse_id'));

        return response()->json(['reading' => $reading, 'kind' => 'X']);
    }

    /**
     * The Z-reading: count the drawer, record the variance, close the shift.
     */
    public function close(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $request->validate([
            'counted_cash' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
        ]);

        $result = TillService::close($id, $request->counted_cash, $request->notes);

        if (isset($result['error'])) {
            $messages = [
                'not_found' => 'No such till session.',
                'not_open' => 'That session is already closed.',
            ];

            return response()->json(['message' => $messages[$result['error']]],
                $result['error'] === 'not_found' ? 404 : 422);
        }

        return response()->json(['reading' => $result['reading'], 'kind' => 'Z']);
    }

    /** Past shifts, newest first. */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $perPage = (int) $request->input('limit', 25);
        $perPage = $perPage > 0 && $perPage <= 200 ? $perPage : 25;
        $page = max(1, (int) $request->input('page', 1));

        $q = DB::table('till_sessions as t')
            ->leftJoin('warehouses as w', 'w.id', '=', 't.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->whereNull('t.deleted_at');

        if ($request->filled('warehouse_id')) {
            $q->where('t.warehouse_id', $request->warehouse_id);
        }
        // Only the user's own warehouses (DATATEST K14).
        $allowed = \App\utils\WarehouseAccess::allowedIds();
        if ($allowed !== null) {
            $q->whereIn('t.warehouse_id', $allowed);
        }
        if ($request->filled('status')) {
            $q->where('t.status', $request->status);
        }
        if ($request->filled('from')) {
            $q->whereDate('t.opened_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $q->whereDate('t.opened_at', '<=', $request->to);
        }

        $totalRows = (clone $q)->count();

        $rows = $q->select('t.*', 'w.name as warehouse', 'u.username as cashier')
            ->orderByDesc('t.id')
            ->offset(($page - 1) * $perPage)->limit($perPage)->get();

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'id' => (int) $r->id,
                'warehouse' => $r->warehouse,
                'terminal' => $r->terminal,
                'cashier' => $r->cashier,
                'opened_at' => $r->opened_at,
                'closed_at' => $r->closed_at,
                'status' => $r->status,
                'z_counter' => $r->z_counter === null ? null : (int) $r->z_counter,
                'opening_float' => round((float) $r->opening_float, 2),
                'expected_cash' => $r->expected_cash === null ? null : round((float) $r->expected_cash, 2),
                'counted_cash' => $r->counted_cash === null ? null : round((float) $r->counted_cash, 2),
                'variance' => $r->variance === null ? null : round((float) $r->variance, 2),
            ];
        }

        return response()->json([
            'sessions' => $data,
            'totalRows' => $totalRows,
            'warehouses' => $this->warehouses(),
        ]);
    }

    /**
     * One shift. A closed one gives back the reading exactly as it was signed
     * off; an open one is recomputed, because it is still moving.
     */
    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $s = DB::table('till_sessions')->where('id', $id)->first();
        if (! $s) {
            return response()->json(['message' => 'No such till session.'], 404);
        }
        \App\utils\WarehouseAccess::assertAllows($s->warehouse_id);

        $stored = $s->reading ? json_decode($s->reading, true) : null;

        return response()->json([
            'reading' => $stored ?: TillService::reading($id),
            'kind' => $s->status === 'closed' ? 'Z' : 'X',
            'reprint' => (bool) $stored,
        ]);
    }

    private function warehouses()
    {
        return Warehouse::where('deleted_at', '=', null)->select('id', 'name')->get();
    }
}
