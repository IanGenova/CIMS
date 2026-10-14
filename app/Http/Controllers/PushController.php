<?php

namespace App\Http\Controllers;

use App\utils\WebPush;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** A device asking for notices when the app is closed (see App\utils\WebPush). */
class PushController extends Controller
{
    public function key()
    {
        return response()->json(['key' => WebPush::publicKey()]);
    }

    public function subscribe(Request $request)
    {
        $request->validate([
            'endpoint' => 'required|string|max:500',
            'keys.p256dh' => 'required|string|max:100',
            'keys.auth' => 'required|string|max:50',
        ]);

        $endpoint = $request->input('endpoint');
        $p256dh = WebPush::unb64u($request->input('keys.p256dh'));
        $auth = WebPush::unb64u($request->input('keys.auth'));

        if (! WebPush::acceptableEndpoint($endpoint) || strlen($p256dh) !== 65 || $p256dh[0] !== "\x04" || strlen($auth) !== 16) {
            return response()->json(['message' => 'This browser gave a push address the system cannot use.'], 422);
        }

        WebPush::subscribe(Auth::user()->id, $endpoint, $request->input('keys.p256dh'), $request->input('keys.auth'));

        return response()->json(['success' => true]);
    }

    /** Signing out, or turning notices off on this device. */
    public function unsubscribe(Request $request)
    {
        WebPush::unsubscribe(Auth::user()->id, (string) $request->input('endpoint'));

        return response()->json(['success' => true]);
    }
}
