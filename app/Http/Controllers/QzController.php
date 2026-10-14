<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Signing for QZ Tray.
 *
 * Unsigned, QZ asks the operator to approve the site every time it starts. That
 * is tolerable while testing and unusable at a till, so QZ lets the server prove
 * itself instead: it holds a certificate and signs each request with the matching
 * private key.
 *
 * Both endpoints are inert until a key pair is configured. They return 404 rather
 * than an empty body or a fake signature, so the browser falls back to prompting
 * -- visibly -- instead of failing in a way nobody can see.
 *
 * To enable, in .env:
 *
 *   QZ_CERTIFICATE=/absolute/path/digital-certificate.txt
 *   QZ_PRIVATE_KEY=/absolute/path/private-key.pem
 *
 * The private key never leaves the server: only the signature does.
 */
class QzController extends Controller
{
    /** The public certificate QZ shows the operator. Not a secret. */
    public function certificate()
    {
        $path = config('app.qz_certificate') ?: env('QZ_CERTIFICATE');

        if (! $path || ! is_readable($path)) {
            return response('QZ signing is not configured on this server.', 404)
                ->header('Content-Type', 'text/plain');
        }

        return response(file_get_contents($path), 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Sign one request with the private key.
     *
     * QZ sends the exact string it wants signed; it must be signed verbatim,
     * with no normalisation, or the signature will not verify.
     */
    public function sign(Request $request)
    {
        $path = config('app.qz_private_key') ?: env('QZ_PRIVATE_KEY');

        if (! $path || ! is_readable($path)) {
            return response('QZ signing is not configured on this server.', 404)
                ->header('Content-Type', 'text/plain');
        }

        $toSign = (string) $request->query('request', '');

        if ($toSign === '') {
            return response('Nothing to sign.', 400)->header('Content-Type', 'text/plain');
        }

        $key = openssl_pkey_get_private(file_get_contents($path));

        if ($key === false) {
            return response('The configured private key could not be read.', 500)
                ->header('Content-Type', 'text/plain');
        }

        $signature = '';
        // SHA512 to match setSignatureAlgorithm on the client. The two must agree.
        $ok = openssl_sign($toSign, $signature, $key, OPENSSL_ALGO_SHA512);

        if (! $ok) {
            return response('Signing failed.', 500)->header('Content-Type', 'text/plain');
        }

        return response(base64_encode($signature), 200)
            ->header('Content-Type', 'text/plain');
    }
}
