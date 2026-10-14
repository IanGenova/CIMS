<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OfflineSaleController extends Controller
{
    /**
     * Bank sales that were captured while the till had no connection.
     *
     * The whole risk of offline capture is double-banking: the browser sends a
     * sale, the response is lost, and the retry books it again. Every offline
     * sale therefore carries a key the client generates once and reuses on
     * every retry; a key that has already been seen returns the original
     * result instead of creating a second sale.
     *
     * Sales are replayed one at a time and independently: one that fails on
     * stock does not stop the rest from banking, and its failure is reported
     * back so the till can show what needs attention.
     */
    public function sync(Request $request)
    {
        // Only someone who may ring up sales may bank them; a refusal is a
        // 403, not a "rejected" key stored for good (DATATEST K19).
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $request->validate([
            'sales' => 'required|array|min:1',
            'sales.*.idempotency_key' => 'required|string|max:128',
        ]);

        $results = [];
        $userId = Auth::user()->id;

        foreach ($request['sales'] as $offline) {
            $key = $offline['idempotency_key'];

            // The key is claimed in the same transaction that banks the sale,
            // and is this user's own (K19/K66): two syncs at once bank it once,
            // and nobody else's key can swallow it.
            try {
                $response = \App\utils\Idempotency::respond($userId, $key, function () use ($offline) {
                    return $this->bank($offline);
                });
                $data = json_decode($response->getContent(), true) ?: [];

                if (! empty($data['duplicate'])) {
                    $results[] = ['idempotency_key' => $key, 'status' => 'duplicate',
                        'sale_id' => $data['id'] ?? null, 'message' => 'This sale was already banked.'];
                } elseif (! empty($data['success']) && ! empty($data['id'])) {
                    $results[] = ['idempotency_key' => $key, 'status' => 'accepted', 'sale_id' => $data['id']];
                } else {
                    $results[] = $this->refused($userId, $key, $data['message'] ?? 'The sale was refused.');
                }
            } catch (\Throwable $e) {
                $results[] = $this->refused($userId, $key, $this->readableError($e));
            }
        }

        $accepted = count(array_filter($results, function ($r) {
            return $r['status'] === 'accepted';
        }));
        $rejected = count(array_filter($results, function ($r) {
            return $r['status'] === 'rejected';
        }));

        return response()->json([
            'success' => true,
            'accepted' => $accepted,
            'rejected' => $rejected,
            'duplicates' => count($results) - $accepted - $rejected,
            'results' => $results,
        ]);
    }

    /** What the till still has outstanding, so it can reconcile on reconnect. */
    public function status(Request $request)
    {
        $request->validate(['keys' => 'nullable|array']);

        $keys = $request['keys'] ?: [];
        // Only this user's own keys: another user's sales are not theirs to see (K66).
        $known = empty($keys)
            ? collect()
            : DB::table('offline_sale_keys')->where('user_id', Auth::user()->id)->whereIn('idempotency_key', $keys)->get();

        return response()->json([
            'known' => $known->map(function ($k) {
                return [
                    'idempotency_key' => $k->idempotency_key,
                    'status' => $k->status,
                    'sale_id' => $k->sale_id,
                    'error' => $k->error,
                ];
            }),
            'server_time' => date('c'),
        ]);
    }

    /** Anything the till could not bank, for someone to look at. */
    public function rejected(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Sale::class);

        $rows = DB::table('offline_sale_keys as k')
            ->leftJoin('users as u', 'u.id', '=', 'k.user_id')
            ->where('k.status', 'rejected')
            ->orderByDesc('k.id')->limit(100)
            ->select('k.*', 'u.username')->get();

        return response()->json(['rejected' => $rows]);
    }

    // ---------------------------------------------------------------

    /**
     * Replay one captured sale through the normal POS controller, so it goes
     * through exactly the same validation, pricing, credit and stock rules as
     * a sale rung up online. Nothing here bypasses them.
     */
    private function bank(array $offline)
    {
        $payload = $offline['payload'];
        $payload['date'] = isset($offline['captured_at']) ? $offline['captured_at'] : date('Y-m-d');
        // The offline key is claimed out here; the inner sale must not claim one too.
        unset($payload['idempotency_key']);

        $inner = Request::create('/api/pos/CreatePOS', 'POST', $payload);
        $inner->setUserResolver(function () {
            return Auth::user();
        });

        return app(PosController::class)->createPosNow($inner);
    }

    /**
     * Note a refusal for the review list -- without blocking a retry: the same
     * sale may bank once the stock is in (DATATEST K19).
     */
    private function refused($userId, $key, $message)
    {
        DB::table('offline_sale_keys')->updateOrInsert(
            ['user_id' => $userId, 'idempotency_key' => $key],
            ['status' => 'rejected', 'sale_id' => null, 'error' => $message, 'created_at' => now(), 'updated_at' => now()]
        );

        return ['idempotency_key' => $key, 'status' => 'rejected', 'message' => $message];
    }

    private function readableError(\Throwable $e)
    {
        if ($e instanceof \Illuminate\Validation\ValidationException) {
            $first = collect($e->errors())->flatten()->first();

            return $first ?: 'The sale did not pass validation.';
        }

        return $e->getMessage();
    }
}
