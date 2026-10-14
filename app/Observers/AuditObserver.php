<?php

namespace App\Observers;

use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Writes an audit row for every create / update / delete / restore on the
 * models registered in AppServiceProvider.
 *
 * Auditing must never break the operation being audited, so every hook is
 * wrapped: if the log write fails the business transaction still completes and
 * the failure is reported to the application log instead.
 */
class AuditObserver
{
    /**
     * Never recorded, in any model. Secrets and machine-generated noise.
     */
    private $neverRecord = [
        'password',
        'remember_token',
        'api_token',
        'updated_at',
    ];

    public function created($model)
    {
        $this->write($model, 'created', null, $this->filter($model->getAttributes()));
    }

    public function updated($model)
    {
        $changed = $this->filter($model->getChanges());

        // An update that only touched ignored fields is not worth a row.
        if (empty($changed)) {
            return;
        }

        $before = [];
        foreach (array_keys($changed) as $key) {
            $before[$key] = $model->getOriginal($key);
        }

        $this->write($model, 'updated', $before, $changed);
    }

    public function deleted($model)
    {
        // A soft delete arrives here too; record it as a deletion either way.
        $this->write($model, 'deleted', $this->filter($model->getAttributes()), null);
    }

    public function restored($model)
    {
        $this->write($model, 'restored', null, $this->filter($model->getAttributes()));
    }

    private function filter(array $attributes)
    {
        return array_diff_key($attributes, array_flip($this->neverRecord));
    }

    private function write($model, $event, $old, $new)
    {
        self::record(get_class($model), $model->getKey(), $event, $old, $new);
    }

    /**
     * For changes written straight to the table, which no observer sees: a
     * customer or supplier deleted, a rate changed, a user switched off, a
     * sale voided, a payment deleted all left no trace (DATATEST K60).
     */
    public static function record($class, $id, $event, $old = null, $new = null)
    {
        try {
            $user = Auth::user();
            $request = request();

            AuditLog::create([
                'user_id' => $user ? $user->id : null,
                'user_name' => $user ? trim($user->firstname . ' ' . $user->lastname) ?: $user->username : null,
                'event' => $event,
                'auditable_type' => $class,
                'auditable_id' => $id,
                'old_values' => $old,
                'new_values' => $new,
                'ip_address' => $request ? $request->ip() : null,
                'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
                'url' => $request ? substr($request->fullUrl(), 0, 255) : null,
                'created_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            // Auditing is never allowed to fail the user's action.
            \Log::error('Audit log write failed', [
                'model' => $class,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
