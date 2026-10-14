<?php

namespace App\utils;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * One sale per key, however often it is sent (DATATEST K19/K40/K66).
 *
 * The key row is claimed (unique per user) in the same transaction that
 * creates the sale. A second request with the same key waits on that row and
 * then finds it taken, so it answers with the first sale instead of making
 * another. If the sale is refused, the transaction takes the claim with it:
 * a sale refused for low stock can be sent again after a restock, where the
 * old code stored the refusal for good.
 */
class Idempotency
{
    /**
     * Run $create (which answers with the usual JSON response) at most once
     * per (user, key). A repeat answers {success, id, duplicate: true}.
     */
    public static function respond($userId, $key, callable $create)
    {
        $key = substr(trim((string) $key), 0, 128);
        if ($key === '') {
            return $create();
        }

        if ($done = self::accepted($userId, $key)) {
            return self::duplicate($done);
        }

        DB::beginTransaction();
        try {
            // Insert first. A DELETE of a missing row before it took gap locks
            // that deadlocked two simultaneous replays (a raw 500).
            try {
                DB::table('offline_sale_keys')->insert([
                    'idempotency_key' => $key,
                    'user_id' => $userId,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
                // The key exists. Locked, so a request still working on it is
                // waited for, and what it finished with is what is read.
                $row = DB::table('offline_sale_keys')->where('user_id', $userId)
                    ->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($row && $row->status === 'accepted') {
                    DB::rollBack();

                    return self::duplicate($row);
                }
                // An old refusal does not stand in the way of a new attempt.
                DB::table('offline_sale_keys')->where('id', $row->id)
                    ->update(['status' => 'pending', 'error' => null, 'updated_at' => now()]);
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        try {
            $response = $create();
            $data = json_decode($response->getContent(), true);
            if ($response->getStatusCode() >= 300 || empty($data['success']) || empty($data['id'])) {
                DB::rollBack();

                return $response;
            }
            DB::table('offline_sale_keys')->where('user_id', $userId)->where('idempotency_key', $key)
                ->update(['status' => 'accepted', 'sale_id' => $data['id'], 'updated_at' => now()]);
            DB::commit();

            return $response;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public static function accepted($userId, $key)
    {
        return DB::table('offline_sale_keys')->where('user_id', $userId)->where('idempotency_key', $key)
            ->where('status', 'accepted')->first();
    }

    private static function duplicate($row)
    {
        return response()->json([
            'success' => true,
            'id' => $row->sale_id,
            'duplicate' => true,
            'message' => 'This sale was already recorded.',
        ]);
    }
}
