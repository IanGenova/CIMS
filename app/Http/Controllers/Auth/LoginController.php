<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use App\utils\PasswordPolicy;
use App\utils\TotpService;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | Password check, then account lockout, then the second factor. The order
    | matters: a locked account is refused before its password is even tested,
    | and a correct password alone is not a session when two-factor is on.
    |
    */

    use AuthenticatesUsers;

    protected $redirectTo = RouteServiceProvider::HOME;

    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }

    protected function credentials(Request $request)
    {
        return ['email' => $request->{$this->username()}, 'password' => $request->password, 'statut' => 1];
    }

    /** Refuse a locked account before testing the password at all. */
    protected function attemptLogin(Request $request)
    {
        $user = DB::table('users')->where('email', $request->{$this->username()})
            ->whereNull('deleted_at')->first();

        if ($user && PasswordPolicy::isLocked($user)) {
            throw ValidationException::withMessages([
                $this->username() => [
                    'This account is locked after too many failed attempts. Try again in '
                    . PasswordPolicy::lockedMinutesRemaining($user) . ' minute(s).',
                ],
            ]);
        }

        return $this->guard()->attempt(
            $this->credentials($request), $request->filled('remember')
        );
    }

    /** Count the miss before handing back the generic failure. */
    protected function sendFailedLoginResponse(Request $request)
    {
        $user = DB::table('users')->where('email', $request->{$this->username()})
            ->whereNull('deleted_at')->first();

        if ($user) {
            PasswordPolicy::recordFailure($user->id);

            $fresh = DB::table('users')->where('id', $user->id)->first();
            if (PasswordPolicy::isLocked($fresh)) {
                throw ValidationException::withMessages([
                    $this->username() => [
                        'Too many failed attempts. This account is locked for '
                        . PasswordPolicy::lockedMinutesRemaining($fresh) . ' minute(s).',
                    ],
                ]);
            }
        }

        throw ValidationException::withMessages([
            $this->username() => [trans('auth.failed')],
        ]);
    }

    /**
     * The password was right. Now the second factor, if this account has one.
     */
    protected function authenticated(Request $request, $user)
    {
        $row = DB::table('users')->where('id', $user->id)->first();

        if ($row->two_factor_confirmed_at) {
            // Not signed in yet: hold the identity in the session and make the
            // browser prove the second factor before a real session exists.
            $this->guard()->logout();
            $request->session()->put('2fa:user_id', $user->id);
            $request->session()->put('2fa:remember', $request->filled('remember'));

            return response()->json([
                'two_factor_required' => true,
                'message' => 'Enter the code from your authenticator app.',
            ]);
        }

        return $this->finishLogin($request, $row);
    }

    /** Complete a sign-in that was held for a second factor. */
    public function twoFactorChallenge(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $userId = $request->session()->get('2fa:user_id');
        if (! $userId) {
            throw ValidationException::withMessages([
                'code' => ['That sign-in has expired. Start again.'],
            ]);
        }

        $row = DB::table('users')->where('id', $userId)->first();
        if (! $row) {
            throw ValidationException::withMessages([
                'code' => ['That sign-in has expired. Start again.'],
            ]);
        }

        if (PasswordPolicy::isLocked($row)) {
            throw ValidationException::withMessages([
                'code' => ['This account is locked. Try again later.'],
            ]);
        }

        $code = preg_replace('/\s+/', '', (string) $request->code);
        $accepted = false;

        // An authenticator code, or one of the single-use recovery codes.
        if ($row->two_factor_secret && TotpService::verify(decrypt($row->two_factor_secret), $code)) {
            $accepted = true;
        } elseif ($row->two_factor_recovery_codes) {
            $codes = json_decode(decrypt($row->two_factor_recovery_codes), true) ?: [];
            $index = array_search(strtoupper($code), array_map('strtoupper', $codes), true);

            if ($index !== false) {
                // Burn it: a recovery code works exactly once.
                unset($codes[$index]);
                DB::table('users')->where('id', $userId)->update([
                    'two_factor_recovery_codes' => encrypt(json_encode(array_values($codes))),
                    'updated_at' => now(),
                ]);
                $accepted = true;
            }
        }

        if (! $accepted) {
            PasswordPolicy::recordFailure($userId);

            throw ValidationException::withMessages([
                'code' => ['That code is not right.'],
            ]);
        }

        $remember = (bool) $request->session()->get('2fa:remember');
        $request->session()->forget(['2fa:user_id', '2fa:remember']);

        Auth::loginUsingId($userId, $remember);
        $request->session()->regenerate();

        return $this->finishLogin($request, $row);
    }

    /**
     * Everything that happens once the identity is settled: stamp the login,
     * and tell the client whether the password has to change before use.
     */
    private function finishLogin(Request $request, $row)
    {
        PasswordPolicy::recordSuccess($row->id, $request->ip());

        $mustChange = (bool) $row->must_change_password || PasswordPolicy::isExpired($row);

        if ($mustChange) {
            $request->session()->put('password:must_change', true);
        }

        $policy = PasswordPolicy::settings();
        $needsTwoFactor = $policy && $policy->require_two_factor && ! $row->two_factor_confirmed_at;

        return response()->json([
            'success' => true,
            'must_change_password' => $mustChange,
            'password_expired' => PasswordPolicy::isExpired($row),
            'two_factor_setup_required' => $needsTwoFactor,
            'email_verified' => $row->email_verified_at !== null,
        ]);
    }
}
