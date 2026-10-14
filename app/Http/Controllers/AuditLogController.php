<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Read-only access to the audit trail.
 *
 * There is deliberately no store/update/destroy: the log is append-only, and
 * the ability to edit it would defeat its purpose.
 */
class AuditLogController extends BaseController
{
    /** Paginated, filterable list of audit entries. */
    public function index(Request $request)
    {
        $this->authorizeAudit($request);

        $request->validate([
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:200',
            'event' => 'nullable|in:created,updated,deleted,restored',
            'user_id' => 'nullable|integer',
            'model' => 'nullable|string|max:100',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'SortType' => 'nullable|in:asc,desc',
        ]);

        $perPage = (int) $request->input('limit', 25);
        $sortType = $request->input('SortType', 'desc');

        $query = AuditLog::query()
            ->when($request->filled('event'), function ($q) use ($request) {
                return $q->where('event', $request->event);
            })
            ->when($request->filled('user_id'), function ($q) use ($request) {
                return $q->where('user_id', $request->user_id);
            })
            ->when($request->filled('model'), function ($q) use ($request) {
                // Accept either "Sale" or the fully qualified class name.
                return $q->where('auditable_type', 'like', '%' . $request->model);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                return $q->where('user_name', 'like', '%' . $request->search . '%');
            })
            ->when($request->filled('from') && $request->filled('to'), function ($q) use ($request) {
                return $q->whereBetween('created_at', [
                    $request->from . ' 00:00:00',
                    $request->to . ' 23:59:59',
                ]);
            });

        $totalRows = $query->count();

        $logs = $query->orderBy('id', $sortType)
            ->offset(($request->input('page', 1) - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $data = [];
        foreach ($logs as $log) {
            $data[] = [
                'id' => $log->id,
                'date' => optional($log->created_at)->toDateTimeString(),
                'user' => $log->user_name ?: 'system',
                'user_id' => $log->user_id,
                'event' => $log->event,
                'record' => $log->auditable_name,
                'record_id' => $log->auditable_id,
                'changes' => $this->summarise($log),
                'ip_address' => $log->ip_address,
            ];
        }

        return response()->json([
            'audit_logs' => $data,
            'totalRows' => $totalRows,
        ]);
    }

    /** Full before/after detail for a single entry. */
    public function show(Request $request, $id)
    {
        $this->authorizeAudit($request);

        $log = AuditLog::findOrFail($id);

        return response()->json([
            'id' => $log->id,
            'date' => optional($log->created_at)->toDateTimeString(),
            'user' => $log->user_name ?: 'system',
            'event' => $log->event,
            'record' => $log->auditable_name,
            'record_id' => $log->auditable_id,
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'url' => $log->url,
        ]);
    }

    /** Everything that happened to one record, oldest first. */
    public function forRecord(Request $request, $model, $id)
    {
        $this->authorizeAudit($request);

        $logs = AuditLog::where('auditable_type', 'like', '%' . $model)
            ->where('auditable_id', $id)
            ->orderBy('id', 'asc')
            ->get();

        $data = [];
        foreach ($logs as $log) {
            $data[] = [
                'id' => $log->id,
                'date' => optional($log->created_at)->toDateTimeString(),
                'user' => $log->user_name ?: 'system',
                'event' => $log->event,
                'changes' => $this->summarise($log),
            ];
        }

        return response()->json(['audit_logs' => $data]);
    }

    /**
     * A short "field: old -> new" list for the table view, so the common case
     * does not need a second request.
     */
    private function summarise(AuditLog $log)
    {
        if ($log->event !== 'updated') {
            return [];
        }

        $old = $log->old_values ?: [];
        $new = $log->new_values ?: [];
        $summary = [];

        foreach ($new as $field => $value) {
            $summary[] = [
                'field' => $field,
                'from' => isset($old[$field]) ? $this->stringify($old[$field]) : null,
                'to' => $this->stringify($value),
            ];
        }

        return $summary;
    }

    private function stringify($value)
    {
        if (is_array($value)) {
            return json_encode($value);
        }

        return is_null($value) ? null : (string) $value;
    }

    /**
     * Only roles carrying the "audit_view" permission may read the trail.
     */
    private function authorizeAudit(Request $request)
    {
        $role = Auth::user()->roles()->first();

        if (! $role || ! Role::findOrFail($role->id)->inRole('audit_view')) {
            return abort(403, 'You are not authorized to view the audit trail.');
        }
    }
}
