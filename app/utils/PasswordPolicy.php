<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Password rules, account lockout, and password expiry.
 *
 * The rules live in a table rather than in a validator, so an administrator
 * can tighten them without a deploy. Everything defaults to something mild,
 * so switching this on does not lock anybody out on day one.
 */
class PasswordPolicy
{
    private static $settings = null;

    public static function forget()
    {
        self::$settings = null;
    }

    public static function settings()
    {
        if (self::$settings === null) {
            self::$settings = DB::table('security_settings')->first();
        }

        return self::$settings;
    }

    /** The rules, phrased for a person rather than a regex. */
    public static function describe()
    {
        $s = self::settings();
        if (! $s) {
            return [];
        }

        $rules = ['At least ' . $s->min_length . ' characters'];
        if ($s->require_uppercase) {
            $rules[] = 'An uppercase letter';
        }
        if ($s->require_lowercase) {
            $rules[] = 'A lowercase letter';
        }
        if ($s->require_number) {
            $rules[] = 'A number';
        }
        if ($s->require_symbol) {
            $rules[] = 'A symbol';
        }
        if ($s->history_count > 0) {
            $rules[] = 'Not one of your last ' . $s->history_count . ' passwords';
        }

        return $rules;
    }

    /**
     * Check a new password against the rules and the user's recent history.
     *
     * @throws ValidationException with every failure at once, so the user is
     *         not sent round the loop one rule at a time.
     */
    public static function assertAcceptable($password, $userId = null)
    {
        $s = self::settings();
        if (! $s) {
            return;
        }

        $problems = [];

        if (strlen($password) < $s->min_length) {
            $problems[] = 'It must be at least ' . $s->min_length . ' characters.';
        }
        if ($s->require_uppercase && ! preg_match('/[A-Z]/', $password)) {
            $problems[] = 'It needs an uppercase letter.';
        }
        if ($s->require_lowercase && ! preg_match('/[a-z]/', $password)) {
            $problems[] = 'It needs a lowercase letter.';
        }
        if ($s->require_number && ! preg_match('/\d/', $password)) {
            $problems[] = 'It needs a number.';
        }
        if ($s->require_symbol && ! preg_match('/[^A-Za-z0-9]/', $password)) {
            $problems[] = 'It needs a symbol.';
        }

        if ($userId && $s->history_count > 0) {
            $recent = DB::table('password_histories')
                ->where('user_id', $userId)
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit($s->history_count)->pluck('password');

            foreach ($recent as $old) {
                if (Hash::check($password, $old)) {
                    $problems[] = 'You have used this password recently. Choose a different one.';
                    break;
                }
            }
        }

        if (! empty($problems)) {
            throw ValidationException::withMessages(['password' => $problems]);
        }
    }

    /** Record a password so it cannot be reused, and prune the tail. */
    public static function remember($userId, $hashedPassword)
    {
        $s = self::settings();
        $keep = $s ? (int) $s->history_count : 3;

        DB::table('password_histories')->insert([
            'user_id' => $userId,
            'password' => $hashedPassword,
            'created_at' => now(),
        ]);

        DB::table('users')->where('id', $userId)->update([
            'password_changed_at' => now(),
            'must_change_password' => false,
        ]);

        if ($keep > 0) {
            $keepIds = DB::table('password_histories')
                ->where('user_id', $userId)
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit($keep)->pluck('id');

            DB::table('password_histories')
                ->where('user_id', $userId)
                ->whereNotIn('id', $keepIds->all() ?: [0])
                ->delete();
        }
    }

    /** Has this password aged out? */
    public static function isExpired($user)
    {
        $s = self::settings();
        if (! $s || (int) $s->expiry_days <= 0) {
            return false;
        }

        if (empty($user->password_changed_at)) {
            return true;
        }

        return strtotime($user->password_changed_at) < strtotime('-' . (int) $s->expiry_days . ' days');
    }

    // ---------------- lockout ----------------

    public static function isLocked($user)
    {
        return ! empty($user->locked_until) && strtotime($user->locked_until) > time();
    }

    public static function lockedMinutesRemaining($user)
    {
        if (! self::isLocked($user)) {
            return 0;
        }

        return (int) ceil((strtotime($user->locked_until) - time()) / 60);
    }

    /** Count a bad attempt, and lock the account once the limit is reached. */
    public static function recordFailure($userId)
    {
        $s = self::settings();
        $max = $s ? (int) $s->max_failed_attempts : 5;
        $minutes = $s ? (int) $s->lockout_minutes : 15;

        if ($max <= 0) {
            return;
        }

        $attempts = (int) DB::table('users')->where('id', $userId)->value('failed_login_attempts') + 1;
        $data = ['failed_login_attempts' => $attempts];

        if ($attempts >= $max) {
            $data['locked_until'] = date('Y-m-d H:i:s', time() + ($minutes * 60));
            $data['failed_login_attempts'] = 0;
        }

        DB::table('users')->where('id', $userId)->update($data);
    }

    public static function recordSuccess($userId, $ip = null)
    {
        DB::table('users')->where('id', $userId)->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ]);
    }
}
