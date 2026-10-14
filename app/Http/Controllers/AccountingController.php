<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\JournalEntry;
use App\utils\LedgerPoster;
use App\utils\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountingController extends Controller
{
    // ==================== chart of accounts ====================

    public function accounts(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Account::class);

        $accounts = Account::where('deleted_at', '=', null)
            ->when($request->filled('search'), function ($q) use ($request) {
                return $q->where(function ($w) use ($request) {
                    $w->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('code', 'LIKE', "%{$request->search}%");
                });
            })
            ->orderBy('code')->get();

        // Balance per account in one grouped query.
        $totals = \DB::table('journal_lines')
            ->groupBy('account_id')
            ->select('account_id', \DB::raw('SUM(debit) as d'), \DB::raw('SUM(credit) as c'))
            ->get()->keyBy('account_id');

        $data = [];
        foreach ($accounts as $a) {
            $t = $totals->get($a->id);
            $d = $t ? (float) $t->d : 0.0;
            $c = $t ? (float) $t->c : 0.0;

            $data[] = [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type,
                'normal_balance' => $a->normal_balance,
                'slug' => $a->slug,
                'is_active' => (bool) $a->is_active,
                'is_system' => (bool) $a->is_system,
                'balance' => round($a->normal_balance === 'debit' ? $d - $c : $c - $d, 2),
            ];
        }

        return response()->json([
            'accounts' => $data,
            'totalRows' => count($data),
            'types' => Account::types(),
        ]);
    }

    public function storeAccount(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Account::class);

        $request->validate([
            'code' => 'required|string|max:32|unique:accounts,code',
            'name' => 'required|string|max:192',
            'type' => 'required|in:asset,liability,equity,income,expense',
            'normal_balance' => 'required|in:debit,credit',
        ]);

        Account::create([
            'code' => $request->code,
            'name' => $request->name,
            'type' => $request->type,
            'normal_balance' => $request->normal_balance,
            'is_active' => $request['is_active'] === null ? true : (bool) $request['is_active'],
            'is_system' => false,
        ]);

        LedgerService::forget();

        return response()->json(['success' => true]);
    }

    public function updateAccount(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Account::class);

        $account = Account::findOrFail($id);

        $request->validate([
            'code' => 'required|string|max:32|unique:accounts,code,' . $id,
            'name' => 'required|string|max:192',
            'type' => 'required|in:asset,liability,equity,income,expense',
            'normal_balance' => 'required|in:debit,credit',
        ]);

        // The posting rules look these up by slug; renaming is fine, retyping
        // one out from under the rules is not.
        if ($account->is_system && $request->type !== $account->type) {
            throw ValidationException::withMessages([
                'type' => ['This is a system account the posting rules depend on. Its type cannot be changed.'],
            ]);
        }

        $account->update([
            'code' => $request->code,
            'name' => $request->name,
            'type' => $request->type,
            'normal_balance' => $request->normal_balance,
            'is_active' => (bool) $request['is_active'],
        ]);

        LedgerService::forget();

        return response()->json(['success' => true]);
    }

    public function destroyAccount(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Account::class);

        $account = Account::findOrFail($id);

        if ($account->is_system) {
            throw ValidationException::withMessages([
                'account' => ['System accounts cannot be deleted; deactivate it instead.'],
            ]);
        }

        $used = \DB::table('journal_lines')->where('account_id', $id)->exists();
        if ($used) {
            throw ValidationException::withMessages([
                'account' => ['This account has journal entries against it. Deactivate it rather than deleting it.'],
            ]);
        }

        Account::whereId($id)->update(['deleted_at' => now()]);

        return response()->json(['success' => true]);
    }

    // ==================== journal ====================

    public function journal(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Account::class);

        $perPage = \App\utils\ListLimit::of($request->limit, 15);
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;

        $q = JournalEntry::query()
            ->when($request->filled('from'), function ($x) use ($request) {
                return $x->where('date', '>=', $request->from);
            })
            ->when($request->filled('to'), function ($x) use ($request) {
                return $x->where('date', '<=', $request->to);
            })
            ->when($request->filled('search'), function ($x) use ($request) {
                return $x->where(function ($w) use ($request) {
                    $w->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('memo', 'LIKE', "%{$request->search}%");
                });
            });

        $totalRows = $q->count();
        $entries = $q->orderByDesc('date')->orderByDesc('id')
            ->offset($offSet)->limit($perPage)->get();

        $ids = $entries->pluck('id')->all();
        $lines = [];
        if (! empty($ids)) {
            $rows = \DB::table('journal_lines as jl')
                ->join('accounts as a', 'a.id', '=', 'jl.account_id')
                ->whereIn('jl.journal_entry_id', $ids)
                ->select('jl.journal_entry_id', 'jl.debit', 'jl.credit', 'jl.memo',
                    'a.code', 'a.name')
                ->get();
            foreach ($rows as $r) {
                $lines[$r->journal_entry_id][] = [
                    'code' => $r->code,
                    'account' => $r->name,
                    'debit' => (float) $r->debit,
                    'credit' => (float) $r->credit,
                    'memo' => $r->memo,
                ];
            }
        }

        $reversed = \DB::table('journal_entries')->whereIn('reverses_entry_id', $ids ?: [0])
            ->pluck('reverses_entry_id')->all();

        $data = [];
        foreach ($entries as $e) {
            $data[] = [
                'id' => $e->id,
                'Ref' => $e->Ref,
                'date' => $e->date ? $e->date->format('Y-m-d') : null,
                'memo' => $e->memo,
                'source_type' => $e->source_type,
                'source_id' => $e->source_id,
                'is_reversal' => $e->reverses_entry_id !== null,
                'is_reversed' => in_array($e->id, $reversed),
                'lines' => isset($lines[$e->id]) ? $lines[$e->id] : [],
            ];
        }

        return response()->json(['entries' => $data, 'totalRows' => $totalRows]);
    }

    public function storeEntry(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Account::class);

        $request->validate([
            'date' => 'required|date',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'required|integer|exists:accounts,id',
        ]);

        $lines = [];
        foreach ($request['lines'] as $l) {
            $lines[] = [
                'account' => (int) $l['account_id'],
                'debit' => isset($l['debit']) ? $l['debit'] : 0,
                'credit' => isset($l['credit']) ? $l['credit'] : 0,
                'memo' => isset($l['memo']) ? $l['memo'] : null,
            ];
        }

        LedgerService::post($lines, $request->date, $request['memo'], 'Manual', null);

        return response()->json(['success' => true]);
    }

    public function reverseEntry(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'create', Account::class);

        LedgerService::reverse($id, $request['date'], $request['memo']);

        return response()->json(['success' => true]);
    }

    // ==================== reports ====================

    public function trialBalance(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Account::class);

        $tb = LedgerService::trialBalance($request->from, $request->to);

        return response()->json($tb + [
            'from' => $request->from,
            'to' => $request->to,
        ]);
    }

    /**
     * Post everything that has happened but has not reached the journal yet.
     *
     * Each posting checks for itself first, so running this twice is safe.
     */
    /**
     * Whether the books are keeping up.
     *
     * Posting happens by itself now, so the useful question is no longer
     * "has anyone pressed the button" but "is anything stuck" -- which is
     * what the waiting count answers.
     */
    public function postingStatus(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Account::class);

        return response()->json([
            'enabled' => LedgerPoster::isEnabled(),
            'pending' => LedgerPoster::pendingCount(),
            'last_posted_at' => LedgerPoster::lastPostedAt(),
        ]);
    }

    /** Turn automatic posting on or off. */
    public function setAutoPost(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Account::class);

        $request->validate(['enabled' => 'required|boolean']);

        \DB::table('settings')->orderBy('id')->limit(1)
            ->update(['auto_post_ledger' => (bool) $request->enabled]);

        return response()->json(['enabled' => LedgerPoster::isEnabled()]);
    }

    public function postPending(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Account::class);

        // The same sweep the scheduler runs, so pressing the button and
        // waiting for the schedule cannot give different answers. It looks
        // for documents with nothing in the journal against them rather than
        // re-asking about every document ever recorded.
        $posted = LedgerPoster::sweep();

        return response()->json([
            'success' => true,
            'posted' => $posted,
            'pending' => LedgerPoster::pendingCount(),
            'last_posted_at' => LedgerPoster::lastPostedAt(),
        ]);
    }

    /** Trial balance as CSV, for handing to an accountant. */
    public function exportTrialBalance(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Account::class);

        $tb = LedgerService::trialBalance($request->from, $request->to);

        $csv = "Code,Account,Type,Debit,Credit,Balance\n";
        foreach ($tb['rows'] as $r) {
            $csv .= sprintf(
                "%s,\"%s\",%s,%.2f,%.2f,%.2f\n",
                $r['code'], str_replace('"', '""', $r['name']), $r['type'],
                $r['debit'], $r['credit'], $r['balance']
            );
        }
        $csv .= sprintf(",\"TOTAL\",,%.2f,%.2f,\n", $tb['total_debit'], $tb['total_credit']);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="trial-balance.csv"',
        ]);
    }
}
