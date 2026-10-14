<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Outgoing webhooks.
 *
 * Every delivery is signed with the endpoint's own secret so the receiver can
 * prove the call came from this system and was not replayed or forged. Each
 * attempt is recorded, and an endpoint that keeps failing is disabled rather
 * than left to hammer a dead URL forever.
 */
class WebhookService
{
    /** Events an endpoint can subscribe to. */
    public static function events()
    {
        return [
            'sale.created', 'sale.completed',
            'purchase.created', 'purchase.received',
            'stock.low', 'stock.expired',
            'delivery.dispatched', 'delivery.delivered',
            'production.completed',
            'payment.received',
        ];
    }

    /** Queue a delivery to every endpoint subscribed to this event. */
    public static function dispatch($event, array $payload)
    {
        $hooks = DB::table('webhooks')
            ->whereNull('deleted_at')->where('is_active', true)->get();

        $queued = 0;
        foreach ($hooks as $h) {
            $subscribed = array_map('trim', explode(',', (string) $h->events));
            if (! in_array($event, $subscribed, true) && ! in_array('*', $subscribed, true)) {
                continue;
            }

            DB::table('webhook_deliveries')->insert([
                'webhook_id' => $h->id,
                'event' => $event,
                'payload' => json_encode([
                    'event' => $event,
                    'sent_at' => date('c'),
                    'data' => $payload,
                ]),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $queued++;
        }

        return $queued;
    }

    /**
     * Attempt the pending deliveries.
     *
     * Run from the queue worker or the scheduler; it is deliberately not run
     * inside the web request, because a slow endpoint would stall the user.
     */
    public static function deliverPending($limit = 25)
    {
        $pending = DB::table('webhook_deliveries')
            ->where('status', 'pending')
            ->orderBy('id')->limit($limit)->get();

        $sent = 0;
        $failed = 0;

        foreach ($pending as $d) {
            $hook = DB::table('webhooks')->where('id', $d->webhook_id)->first();
            if (! $hook || ! $hook->is_active) {
                DB::table('webhook_deliveries')->where('id', $d->id)
                    ->update(['status' => 'failed', 'response_body' => 'Endpoint is inactive.', 'updated_at' => now()]);
                $failed++;
                continue;
            }

            $signature = hash_hmac('sha256', $d->payload, $hook->secret);

            try {
                $response = Http::timeout(10)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'X-CIMS-Event' => $d->event,
                        'X-CIMS-Signature' => $signature,
                        'X-CIMS-Delivery' => (string) $d->id,
                    ])
                    ->withBody($d->payload, 'application/json')
                    ->post($hook->url);

                $ok = $response->successful();

                DB::table('webhook_deliveries')->where('id', $d->id)->update([
                    'attempts' => $d->attempts + 1,
                    'response_status' => $response->status(),
                    'response_body' => substr((string) $response->body(), 0, 2000),
                    'status' => $ok ? 'delivered' : ($d->attempts + 1 >= 3 ? 'failed' : 'pending'),
                    'delivered_at' => $ok ? now() : null,
                    'updated_at' => now(),
                ]);

                if ($ok) {
                    DB::table('webhooks')->where('id', $hook->id)->update([
                        'failure_count' => 0,
                        'last_success_at' => now(),
                        'last_error' => null,
                        'updated_at' => now(),
                    ]);
                    $sent++;
                } else {
                    self::recordFailure($hook, 'HTTP ' . $response->status());
                    $failed++;
                }
            } catch (\Throwable $e) {
                DB::table('webhook_deliveries')->where('id', $d->id)->update([
                    'attempts' => $d->attempts + 1,
                    'response_body' => substr($e->getMessage(), 0, 2000),
                    'status' => $d->attempts + 1 >= 3 ? 'failed' : 'pending',
                    'updated_at' => now(),
                ]);

                self::recordFailure($hook, $e->getMessage());
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /** A fresh signing secret. */
    public static function generateSecret()
    {
        return bin2hex(random_bytes(24));
    }

    /**
     * What a receiver has to do to check a delivery, written out so it can be
     * shown on screen rather than left to guesswork.
     */
    public static function signatureHelp()
    {
        return 'Each request carries X-CIMS-Signature: an HMAC-SHA256 of the raw '
            . 'request body, keyed with this endpoint\'s secret. Recompute it on '
            . 'your side and compare before trusting the payload.';
    }

    private static function recordFailure($hook, $message)
    {
        $count = (int) $hook->failure_count + 1;
        $data = [
            'failure_count' => $count,
            'last_failure_at' => now(),
            'last_error' => substr($message, 0, 1000),
            'updated_at' => now(),
        ];

        // Stop calling an endpoint that has been dead for twenty attempts.
        if ($count >= 20) {
            $data['is_active'] = false;
        }

        DB::table('webhooks')->where('id', $hook->id)->update($data);
    }
}
