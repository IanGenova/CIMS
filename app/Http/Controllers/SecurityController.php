<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\utils\PasswordPolicy;
use App\utils\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SecurityController extends Controller
{
    // ==================== the signed-in user's own security ====================

    /** What this account's security currently looks like. */
    public function me(Request $request)
    {
        $user = Auth::user();
        $row = DB::table('users')->where('id', $user->id)->first();

        return response()->json([
            'two_factor_enabled' => $row->two_factor_confirmed_at !== null,
            'email_verified' => $row->email_verified_at !== null,
            'must_change_password' => (bool) $row->must_change_password,
            'password_changed_at' => $row->password_changed_at,
            'password_expired' => PasswordPolicy::isExpired($row),
            'last_login_at' => $row->last_login_at,
            'last_login_ip' => $row->last_login_ip,
            'password_rules' => PasswordPolicy::describe(),
            'two_factor_required' => (bool) (PasswordPolicy::settings()->require_two_factor ?? false),
            'recovery_codes_left' => $row->two_factor_recovery_codes
                ? count(array_filter(json_decode(decrypt($row->two_factor_recovery_codes), true) ?: []))
                : 0,
        ]);
    }

    /**
     * Begin two-factor setup. The secret is stored but not yet confirmed, so
     * an abandoned setup never locks anyone out.
     */
    public function startTwoFactor(Request $request)
    {
        $user = Auth::user();

        // Starting again clears the confirmed secret, which switches 2FA off.
        // While it is on, that needs the password, exactly as Disable does
        // (DATATEST K63).
        $row = DB::table('users')->where('id', $user->id)->first(['password', 'two_factor_confirmed_at']);
        if ($row && $row->two_factor_confirmed_at !== null) {
            if (! \Illuminate\Support\Facades\Hash::check((string) $request->input('password'), $row->password)) {
                return response()->json([
                    'message' => 'Two-factor is already on. Enter your password to set it up again.',
                    'errors' => ['password' => ['Enter your current password.']],
                ], 422);
            }
        }

        $secret = TotpService::generateSecret();

        DB::table('users')->where('id', $user->id)->update([
            'two_factor_secret' => encrypt($secret),
            'two_factor_confirmed_at' => null,
            'updated_at' => now(),
        ]);

        $company = DB::table('settings')->value('CompanyName') ?: 'CIMS';

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => TotpService::provisioningUri($secret, $user->email, $company),
        ]);
    }

    /** Confirm setup by proving the authenticator produces the right code. */
    public function confirmTwoFactor(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $user = Auth::user();
        $row = DB::table('users')->where('id', $user->id)->first();

        if (! $row->two_factor_secret) {
            throw ValidationException::withMessages([
                'code' => ['Start two-factor setup first.'],
            ]);
        }

        $secret = decrypt($row->two_factor_secret);

        if (! TotpService::verify($secret, $request->code)) {
            throw ValidationException::withMessages([
                'code' => ['That code is not right. Check your authenticator and try again.'],
            ]);
        }

        $codes = TotpService::generateRecoveryCodes();

        DB::table('users')->where('id', $user->id)->update([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode($codes)),
            'updated_at' => now(),
        ]);

        // Shown once. They are hashed nowhere, so this is the only chance.
        return response()->json(['success' => true, 'recovery_codes' => $codes]);
    }

    /** Turning it off requires the current password, not just a session. */
    public function disableTwoFactor(Request $request)
    {
        $request->validate(['password' => 'required|string']);

        $user = Auth::user();
        if (! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['That password is not correct.'],
            ]);
        }

        if (PasswordPolicy::settings() && PasswordPolicy::settings()->require_two_factor) {
            throw ValidationException::withMessages([
                'two_factor' => ['Two-factor authentication is required for every account and cannot be turned off.'],
            ]);
        }

        DB::table('users')->where('id', $user->id)->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function regenerateRecoveryCodes(Request $request)
    {
        $user = Auth::user();
        $row = DB::table('users')->where('id', $user->id)->first();

        if (! $row->two_factor_confirmed_at) {
            throw ValidationException::withMessages([
                'two_factor' => ['Two-factor authentication is not switched on for this account.'],
            ]);
        }

        $codes = TotpService::generateRecoveryCodes();
        DB::table('users')->where('id', $user->id)->update([
            'two_factor_recovery_codes' => encrypt(json_encode($codes)),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'recovery_codes' => $codes]);
    }

    /** Change your own password, subject to the policy. */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|confirmed',
        ]);

        $user = Auth::user();

        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['That password is not correct.'],
            ]);
        }

        if (Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The new password must be different from the current one.'],
            ]);
        }

        PasswordPolicy::assertAcceptable($request->password, $user->id);

        $hashed = Hash::make($request->password);
        DB::table('users')->where('id', $user->id)->update([
            'password' => $hashed,
            'updated_at' => now(),
        ]);
        PasswordPolicy::remember($user->id, $hashed);

        return response()->json(['success' => true]);
    }

    // ==================== sessions ====================

    /** Where this account is currently signed in. */
    public function sessions(Request $request)
    {
        $user = Auth::user();

        if (! \Schema::hasTable('sessions')) {
            return response()->json(['sessions' => [], 'driver' => config('session.driver')]);
        }

        $rows = DB::table('sessions')->where('user_id', $user->id)
            ->orderByDesc('last_activity')->get();

        $current = $request->hasSession() ? $request->session()->getId() : null;

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => $r->id,
                'ip_address' => $r->ip_address,
                'user_agent' => $r->user_agent,
                'last_activity' => date('Y-m-d H:i:s', $r->last_activity),
                'is_current' => $current !== null && $r->id === $current,
            ];
        }

        return response()->json(['sessions' => $out, 'driver' => config('session.driver')]);
    }

    /**
     * Sign out everywhere except here. Requires the password, because someone
     * with a borrowed session should not be able to lock the owner out.
     */
    public function signOutOthers(Request $request)
    {
        $request->validate(['password' => 'required|string']);

        $user = Auth::user();
        if (! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['That password is not correct.'],
            ]);
        }

        if (! \Schema::hasTable('sessions')) {
            throw ValidationException::withMessages([
                'sessions' => ['Sessions are not stored in the database, so other devices cannot be signed out from here. Set SESSION_DRIVER=database.'],
            ]);
        }

        $current = $request->hasSession() ? $request->session()->getId() : null;

        $removed = DB::table('sessions')->where('user_id', $user->id)
            ->when($current, function ($q) use ($current) {
                return $q->where('id', '!=', $current);
            })->delete();

        // Revoke API tokens too, or a signed-out device keeps its API access.
        $tokensRevoked = 0;
        if (\Schema::hasTable('oauth_access_tokens')) {
            $tokensRevoked = DB::table('oauth_access_tokens')
                ->where('user_id', $user->id)->where('revoked', false)
                ->update(['revoked' => true]);
        }

        return response()->json([
            'success' => true,
            'sessions_ended' => $removed,
            'tokens_revoked' => $tokensRevoked,
            // Called over the API there is no session store to read, so the
            // browser's own session cannot be singled out. Everything is ended
            // rather than guessing which row to spare.
            'signed_out_everywhere' => $current === null,
        ]);
    }

    // ==================== policy administration ====================

    public function getPolicy(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', User::class);
        PasswordPolicy::forget();

        $settings = (array) PasswordPolicy::settings();

        // MySQL hands tinyint back as 0/1 integers. A checkbox bound to those
        // renders unchecked, which would show every rule as off and then save
        // it that way -- silently weakening the policy. Cast them properly.
        foreach (['require_uppercase', 'require_lowercase', 'require_number',
                  'require_symbol', 'require_two_factor'] as $flag) {
            if (array_key_exists($flag, $settings)) {
                $settings[$flag] = (bool) $settings[$flag];
            }
        }
        foreach (['min_length', 'expiry_days', 'history_count',
                  'max_failed_attempts', 'lockout_minutes'] as $number) {
            if (array_key_exists($number, $settings)) {
                $settings[$number] = (int) $settings[$number];
            }
        }

        return response()->json([
            'settings' => $settings,
            'session_driver' => config('session.driver'),
            'queue_driver' => config('queue.default'),
        ]);
    }

    public function updatePolicy(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', User::class);

        $request->validate([
            'min_length' => 'required|integer|min:6|max:128',
            'expiry_days' => 'required|integer|min:0|max:3650',
            'history_count' => 'required|integer|min:0|max:24',
            'max_failed_attempts' => 'required|integer|min:0|max:100',
            'lockout_minutes' => 'required|integer|min:1|max:1440',
        ]);

        DB::table('security_settings')->update([
            'min_length' => $request->min_length,
            'require_uppercase' => (bool) $request['require_uppercase'],
            'require_lowercase' => (bool) $request['require_lowercase'],
            'require_number' => (bool) $request['require_number'],
            'require_symbol' => (bool) $request['require_symbol'],
            'expiry_days' => $request->expiry_days,
            'history_count' => $request->history_count,
            'max_failed_attempts' => $request->max_failed_attempts,
            'lockout_minutes' => $request->lockout_minutes,
            'require_two_factor' => (bool) $request['require_two_factor'],
            'updated_at' => now(),
        ]);

        PasswordPolicy::forget();

        return response()->json(['success' => true]);
    }

    /** Who has two-factor on, who is locked out, whose password has expired. */
    public function userSecurity(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', User::class);

        $users = DB::table('users')->whereNull('deleted_at')
            ->select('id', 'firstname', 'lastname', 'username', 'email',
                'two_factor_confirmed_at', 'email_verified_at', 'password_changed_at',
                'must_change_password', 'locked_until', 'failed_login_attempts',
                'last_login_at', 'last_login_ip')
            ->orderBy('username')->get();

        $out = [];
        foreach ($users as $u) {
            $out[] = [
                'id' => $u->id,
                'name' => trim($u->firstname . ' ' . $u->lastname) ?: $u->username,
                'username' => $u->username,
                'email' => $u->email,
                'two_factor' => $u->two_factor_confirmed_at !== null,
                'email_verified' => $u->email_verified_at !== null,
                'must_change_password' => (bool) $u->must_change_password,
                'password_expired' => PasswordPolicy::isExpired($u),
                'locked' => PasswordPolicy::isLocked($u),
                'locked_minutes' => PasswordPolicy::lockedMinutesRemaining($u),
                'failed_attempts' => (int) $u->failed_login_attempts,
                'last_login_at' => $u->last_login_at,
                'last_login_ip' => $u->last_login_ip,
            ];
        }

        return response()->json(['users' => $out]);
    }

    /** Release a locked account without waiting for the timer. */
    public function unlockUser(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', User::class);

        DB::table('users')->where('id', $id)->update([
            'locked_until' => null,
            'failed_login_attempts' => 0,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    /** Make a user pick a new password at their next sign-in. */
    public function forcePasswordChange(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', User::class);

        DB::table('users')->where('id', $id)->update([
            'must_change_password' => true,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    /** Clear another user's two-factor, for when they have lost the device. */
    public function resetTwoFactor(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', User::class);

        DB::table('users')->where('id', $id)->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }
}
