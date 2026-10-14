<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\utils\CreditService;
use Illuminate\Http\Request;

class CreditController extends Controller
{
    /**
     * Every customer with a limit or a balance, and how close they are to it.
     */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Client::class);

        $clients = Client::where('deleted_at', '=', null)
            ->when($request->filled('search'), function ($q) use ($request) {
                return $q->where('name', 'LIKE', "%{$request->search}%");
            })
            ->orderBy('name')->get();

        $rows = [];
        $totalOutstanding = 0.0;
        $overLimit = 0;

        foreach ($clients as $c) {
            $b = CreditService::balanceFor($c->id);
            $limit = (float) $c->credit_limit;

            // A customer with no limit and nothing owing is just noise here.
            if ($limit <= 0 && $b['balance'] == 0.0) {
                continue;
            }

            $isOver = $limit > 0 && $b['balance'] > $limit;
            if ($isOver) {
                $overLimit++;
            }
            $totalOutstanding += $b['balance'];

            $rows[] = [
                'client_id' => $c->id,
                'client' => $c->name,
                'credit_limit' => $limit,
                'opening' => $b['opening'],
                'invoiced' => $b['invoiced'],
                'paid' => $b['paid'],
                'returned' => $b['returned'],
                'balance' => $b['balance'],
                'available' => $limit > 0 ? round($limit - $b['balance'], 2) : null,
                'utilisation' => $limit > 0 ? round(($b['balance'] / $limit) * 100, 1) : null,
                'over_limit' => $isOver,
            ];
        }

        // Worst first: this screen exists to surface exposure.
        usort($rows, function ($a, $b) {
            return $b['balance'] <=> $a['balance'];
        });

        return response()->json([
            'customers' => $rows,
            'totalRows' => count($rows),
            'total_outstanding' => round($totalOutstanding, 2),
            'over_limit_count' => $overLimit,
        ]);
    }

    /** One customer's statement: the balance, and the invoices behind it. */
    public function statement(Request $request, $clientId)
    {
        $this->authorizeForUser($request->user('api'), 'view', Client::class);

        $client = Client::findOrFail($clientId);
        $balance = CreditService::balanceFor($clientId);
        $ageing = CreditService::ageing($clientId);

        return response()->json([
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
                'phone' => $client->phone,
                'credit_limit' => (float) $client->credit_limit,
            ],
            'balance' => $balance,
            'buckets' => $ageing['buckets'],
            'rows' => $ageing['rows'],
            'total_due' => $ageing['total'],
        ]);
    }

    /** Ageing across every customer. */
    public function ageing(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Client::class);

        $ageing = CreditService::ageing(null, $request->as_of);

        return response()->json([
            'buckets' => $ageing['buckets'],
            'rows' => $ageing['rows'],
            'total_due' => $ageing['total'],
            'as_of' => $request->as_of ?: date('Y-m-d'),
        ]);
    }
}
