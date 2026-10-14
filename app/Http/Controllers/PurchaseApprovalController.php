<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\Role;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Purchase approval.
 *
 * Any user with the purchase permission could previously commit inventory and
 * supplier liability alone. Purchases at or above the threshold in Settings
 * are now held at "pending" until someone holding purchases_approve signs
 * them off; a rejected purchase can never be received.
 *
 * Approving is deliberately separated from raising: a user cannot approve a
 * purchase they created themselves unless they are the only approver.
 */
class PurchaseApprovalController extends BaseController
{
    /** The configured threshold, or null when approval is switched off. */
    public static function threshold()
    {
        $setting = Setting::where('deleted_at', '=', null)->first();

        if (! $setting) {
            return null;
        }

        return $setting->purchase_approval_threshold;
    }

    /**
     * What a new purchase's approval_status should be.
     * Called by PurchasesController when a purchase is created.
     */
    public static function initialStatusFor($grandTotal)
    {
        $threshold = self::threshold();

        if ($threshold === null) {
            return 'not_required';
        }

        return (float) $grandTotal >= (float) $threshold ? 'pending' : 'not_required';
    }

    /** Purchases waiting for someone to sign them off. */
    public function pending(Request $request)
    {
        $this->authorizeApproval($request);

        $purchases = Purchase::where('deleted_at', '=', null)
            ->where('approval_status', 'pending')
            ->with(['provider', 'warehouse', 'user'])
            ->orderBy('id', 'desc')
            ->get();

        $data = [];
        foreach ($purchases as $purchase) {
            $data[] = [
                'id' => $purchase->id,
                'Ref' => $purchase->Ref,
                'date' => $purchase->date,
                'supplier' => $purchase->provider ? $purchase->provider->name : '—',
                'warehouse' => $purchase->warehouse ? $purchase->warehouse->name : '—',
                'raised_by' => $purchase->user ? $purchase->user->username : '—',
                'raised_by_id' => $purchase->user_id,
                'GrandTotal' => (float) $purchase->GrandTotal,
                'statut' => $purchase->statut,
            ];
        }

        return response()->json([
            'purchases' => $data,
            'threshold' => self::threshold(),
        ]);
    }

    public function approve(Request $request, $id)
    {
        $this->authorizeApproval($request);

        $purchase = Purchase::findOrFail($id);

        if ($purchase->approval_status !== 'pending') {
            return response()->json([
                'message' => 'This purchase is not awaiting approval (it is ' . $purchase->approval_status . ').',
            ], 422);
        }

        $purchase->update([
            'approval_status' => 'approved',
            'approved_by' => Auth::user()->id,
            'approved_at' => Carbon::now(),
            'rejection_reason' => null,
        ]);

        // The buyer hears it was approved; the stockroom that it is coming.
        \App\utils\ActivityNotifier::purchaseApproved($purchase->id);

        return response()->json(['success' => true]);
    }

    public function reject(Request $request, $id)
    {
        $this->authorizeApproval($request);

        $request->validate(['reason' => 'required|string|max:1000']);

        $purchase = Purchase::findOrFail($id);

        if ($purchase->approval_status !== 'pending') {
            return response()->json([
                'message' => 'This purchase is not awaiting approval (it is ' . $purchase->approval_status . ').',
            ], 422);
        }

        $purchase->update([
            'approval_status' => 'rejected',
            'approved_by' => Auth::user()->id,
            'approved_at' => Carbon::now(),
            'rejection_reason' => $request->reason,
        ]);

        // The buyer hears why.
        \App\utils\ActivityNotifier::purchaseRejected($purchase->id);

        return response()->json(['success' => true]);
    }

    private function authorizeApproval(Request $request)
    {
        $role = Auth::user()->roles()->first();

        if (! $role || ! Role::findOrFail($role->id)->inRole('purchases_approve')) {
            return abort(403, 'You are not authorized to approve purchases.');
        }
    }
}
