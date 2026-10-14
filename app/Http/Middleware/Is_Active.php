<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Is_Active
{
    /**
     * While an account has something to put right, only these are open: the
     * user's own Security page (password, two-factor) and the call the app
     * makes to learn who is signed in.
     */
    const OPEN_WHILE_BLOCKED = ['api/security/*', 'api/GetUserAuth', 'api/user'];

    public function handle($request, Closure $next)
    {
        $user = $request->user() ?: $request->user('api');
        if ($user && !$user->statut) {
            return $this->inactiveResponse($request);
        }

        // A flag the owner set, or a policy the owner switched on, is only
        // real if the API refuses to work until it is met (DATATEST K86/K87).
        if ($user && ($action = $this->pendingAction($user)) && ! $request->is(...self::OPEN_WHILE_BLOCKED)) {
            return response()->json([
                'message' => $action === 'change_password'
                    ? 'You must change your password before you continue. Open Security from your user menu.'
                    : 'Two-factor sign-in is required. Set it up on the Security page before you continue.',
                'account_action' => $action,
            ], 403);
        }

        $response = $next($request);

        // Login requests are unauthenticated before $next(), so check again.
        $user = $request->user() ?: $request->user('api') ?: Auth::user();
        if ($user && !$user->statut) {
            return $this->inactiveResponse($request);
        }

        return $response;
    }

    /** 'change_password', 'two_factor', or null when nothing is owed. */
    private function pendingAction($user)
    {
        $row = DB::table('users')->where('id', $user->id)
            ->first(['must_change_password', 'two_factor_confirmed_at']);
        if (! $row) {
            return null;
        }
        if ((int) $row->must_change_password === 1) {
            return 'change_password';
        }
        if ($row->two_factor_confirmed_at === null
            && (int) (DB::table('security_settings')->value('require_two_factor') ?? 0) === 1) {
            return 'two_factor';
        }

        return null;
    }

    private function inactiveResponse($request)
    {
        // The session guard can sign out; the API's token guard cannot, so its
        // token is revoked instead. Calling logout() on it was a 500 (K67).
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }
        $apiUser = $request->user('api');
        $token = $apiUser && method_exists($apiUser, 'token') ? $apiUser->token() : null;
        if ($token && method_exists($token, 'revoke')) {
            $token->revoke();
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'Your account is inactive. Please contact an administrator.',
            ], 403);
        }

        return redirect('/login')->with('erro_login', 'Your account is inactive.');
    }
}
