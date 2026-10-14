<?php

namespace App\Http\Controllers;

use App\utils\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    /** What the bell should show. */
    public function index(Request $request)
    {
        $user = Auth::user();

        return response()->json([
            'notifications' => NotificationService::forUser($user->id, $this->permissions(), 20),
            'unread' => NotificationService::unreadCount($user->id, $this->permissions()),
        ]);
    }

    /** Just the badge number, for polling without pulling the whole list. */
    public function count(Request $request)
    {
        $user = Auth::user();

        return response()->json([
            'unread' => NotificationService::unreadCount($user->id, $this->permissions()),
        ]);
    }

    public function markRead(Request $request, $id)
    {
        NotificationService::markRead($id, Auth::user()->id);

        return response()->json(['success' => true]);
    }

    public function markAllRead(Request $request)
    {
        NotificationService::markAllRead(Auth::user()->id, $this->permissions());

        return response()->json(['success' => true]);
    }

    /**
     * Re-scan for things that need attention.
     *
     * Called by the scheduler; also exposed so the bell can refresh on demand
     * without waiting for the next scheduled run.
     */
    public function refresh(Request $request)
    {
        $created = NotificationService::generateAlerts();

        return response()->json([
            'success' => true,
            'created' => $created,
            'unread' => NotificationService::unreadCount(Auth::user()->id, $this->permissions()),
        ]);
    }

    /** The permission names this user holds, for broadcast targeting. */
    private function permissions()
    {
        $user = Auth::user();
        $role = $user->roles()->first();

        if (! $role) {
            return [];
        }

        return \DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permission_role.role_id', $role->id)
            ->pluck('permissions.name')->all();
    }
}
