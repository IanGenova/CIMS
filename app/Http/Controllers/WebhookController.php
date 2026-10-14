<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\utils\WebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', User::class);

        $hooks = DB::table('webhooks')->whereNull('deleted_at')->orderByDesc('id')->get();

        $rows = [];
        foreach ($hooks as $h) {
            $pending = DB::table('webhook_deliveries')->where('webhook_id', $h->id)
                ->where('status', 'pending')->count();
            $failed = DB::table('webhook_deliveries')->where('webhook_id', $h->id)
                ->where('status', 'failed')->count();

            $rows[] = [
                'id' => $h->id,
                'name' => $h->name,
                'url' => $h->url,
                'events' => array_filter(array_map('trim', explode(',', (string) $h->events))),
                'is_active' => (bool) $h->is_active,
                'failure_count' => (int) $h->failure_count,
                'last_success_at' => $h->last_success_at,
                'last_failure_at' => $h->last_failure_at,
                'last_error' => $h->last_error,
                'pending' => $pending,
                'failed' => $failed,
                // The secret is write-once; showing it again would be pointless
                // as well as careless, since the receiver already has it.
                'secret_hint' => substr($h->secret, 0, 6) . '…',
            ];
        }

        return response()->json([
            'webhooks' => $rows,
            'available_events' => WebhookService::events(),
            'signature_help' => WebhookService::signatureHelp(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', User::class);

        $request->validate([
            'name' => 'required|string|max:192',
            'url' => 'required|url|max:512',
            'events' => 'required|array|min:1',
        ]);

        $secret = WebhookService::generateSecret();

        DB::table('webhooks')->insert([
            'name' => $request->name,
            'url' => $request->url,
            'events' => implode(',', $request->events),
            'secret' => $secret,
            'is_active' => $request['is_active'] === null ? true : (bool) $request['is_active'],
            'user_id' => Auth::user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Shown once, at creation, so it can be pasted into the receiver.
        return response()->json(['success' => true, 'secret' => $secret]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', User::class);

        $request->validate([
            'name' => 'required|string|max:192',
            'url' => 'required|url|max:512',
            'events' => 'required|array|min:1',
        ]);

        DB::table('webhooks')->where('id', $id)->update([
            'name' => $request->name,
            'url' => $request->url,
            'events' => implode(',', $request->events),
            'is_active' => (bool) $request['is_active'],
            // Re-enabling by hand clears the strike count.
            'failure_count' => $request['is_active'] ? 0 : DB::raw('failure_count'),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', User::class);

        DB::table('webhooks')->where('id', $id)->update(['deleted_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function rotateSecret(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', User::class);

        $secret = WebhookService::generateSecret();
        DB::table('webhooks')->where('id', $id)->update([
            'secret' => $secret,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'secret' => $secret]);
    }

    /** Fire a test payload so the endpoint can be proved before it matters. */
    public function test(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', User::class);

        $hook = DB::table('webhooks')->where('id', $id)->first();
        if (! $hook) {
            return response()->json(['success' => false], 404);
        }

        DB::table('webhook_deliveries')->insert([
            'webhook_id' => $id,
            'event' => 'test.ping',
            'payload' => json_encode([
                'event' => 'test.ping',
                'sent_at' => date('c'),
                'data' => ['message' => 'This is a test delivery from CIMS.'],
            ]),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = WebhookService::deliverPending(5);

        $latest = DB::table('webhook_deliveries')->where('webhook_id', $id)
            ->orderByDesc('id')->first();

        return response()->json([
            'success' => true,
            'result' => $result,
            'status' => $latest ? $latest->status : null,
            'response_status' => $latest ? $latest->response_status : null,
            'response_body' => $latest ? substr((string) $latest->response_body, 0, 500) : null,
        ]);
    }

    public function deliveries(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', User::class);

        $rows = DB::table('webhook_deliveries as d')
            ->join('webhooks as w', 'w.id', '=', 'd.webhook_id')
            ->when($request->filled('webhook_id'), function ($q) use ($request) {
                return $q->where('d.webhook_id', $request->webhook_id);
            })
            ->orderByDesc('d.id')->limit(100)
            ->select('d.id', 'd.event', 'd.status', 'd.attempts', 'd.response_status',
                'd.response_body', 'd.delivered_at', 'd.created_at', 'w.name as webhook')
            ->get();

        return response()->json(['deliveries' => $rows]);
    }

    /** Push whatever is queued, for when there is no worker running. */
    public function deliverPending(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', User::class);

        return response()->json(['success' => true] + WebhookService::deliverPending(50));
    }
}
