<?php

namespace App\utils;

/**
 * Time-based one-time passwords (RFC 6238), implemented directly.
 *
 * This is deliberately dependency-free: it is roughly forty lines of HMAC and
 * base32, and adding a package for it would mean a composer install on a
 * machine that may not have network access. The algorithm is fixed by the
 * standard, so there is nothing to keep up to date.
 */
class TotpService
{
    const PERIOD = 30;      // seconds per code
    const DIGITS = 6;
    const ALGORITHM = 'sha1';
    // How many periods either side of now are accepted, to tolerate clock drift.
    const WINDOW = 1;

    private static $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A fresh 160-bit secret, base32 encoded as authenticator apps expect. */
    public static function generateSecret($length = 32)
    {
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::$alphabet[random_int(0, 31)];
        }

        return $secret;
    }

    /** The otpauth:// URI an authenticator app scans. */
    public static function provisioningUri($secret, $account, $issuer)
    {
        return 'otpauth://totp/'
            . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=' . strtoupper(self::ALGORITHM)
            . '&digits=' . self::DIGITS
            . '&period=' . self::PERIOD;
    }

    /** The code for a given counter, or for now when none is given. */
    public static function codeAt($secret, $counter = null)
    {
        if ($counter === null) {
            $counter = (int) floor(time() / self::PERIOD);
        }

        $key = self::base32Decode($secret);
        if ($key === false || $key === '') {
            return null;
        }

        // Counter as a big-endian 64-bit integer.
        $binCounter = pack('N*', 0, $counter);
        $hash = hash_hmac(self::ALGORITHM, $binCounter, $key, true);

        // Dynamic truncation, per the RFC.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        $code = $value % (10 ** self::DIGITS);

        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Check a submitted code, allowing for a little clock drift.
     *
     * Comparison is constant-time so a wrong code cannot be narrowed down by
     * timing how long the rejection took.
     */
    public static function verify($secret, $code)
    {
        $code = preg_replace('/\s+/', '', (string) $code);
        if (! preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }

        $current = (int) floor(time() / self::PERIOD);

        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $candidate = self::codeAt($secret, $current + $i);
            if ($candidate !== null && hash_equals($candidate, $code)) {
                return true;
            }
        }

        return false;
    }

    /** Single-use codes for when the authenticator device is lost. */
    public static function generateRecoveryCodes($count = 8)
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(3)) . '-' . bin2hex(random_bytes(3)));
        }

        return $codes;
    }

    // ---------------------------------------------------------------

    private static function base32Decode($input)
    {
        $input = rtrim(strtoupper($input), '=');
        if ($input === '') {
            return '';
        }

        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        for ($i = 0; $i < strlen($input); $i++) {
            $index = strpos(self::$alphabet, $input[$i]);
            if ($index === false) {
                return false;
            }

            $buffer = ($buffer << 5) | $index;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}
