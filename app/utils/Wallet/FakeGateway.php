<?php

namespace App\utils\Wallet;

/**
 * A provider that does exactly what it is told, and nothing over the network.
 *
 * This exists so the decisions can be tested: what happens when the provider
 * says a different amount arrived, when it never settles, when it expires, when
 * a callback claims success before the provider agrees. Those are the cases that
 * lose money, and none of them can be provoked reliably against a real sandbox.
 *
 * It holds its state in memory, so a payment marked paid here is paid for the
 * rest of the process and no longer.
 */
class FakeGateway implements WalletGateway
{
    /** reference => ['status' => ..., 'amount' => ...] */
    private $charges = [];

    private $failCreate = false;

    public function createCharge(array $params)
    {
        if ($this->failCreate) {
            return ['ok' => false, 'charge_id' => null, 'status' => 'failed',
                    'action' => ['type' => 'none', 'value' => ''],
                    'raw' => [], 'error' => 'the provider refused the charge'];
        }

        $reference = $params['reference_id'];
        $this->charges[$reference] = [
            'status' => 'pending',
            'amount' => (float) $params['amount'],
        ];

        return [
            'ok' => true,
            'charge_id' => 'fake_' . substr(hash('sha256', $reference), 0, 20),
            'status' => 'pending',
            'action' => [
                'type' => 'qr',
                'value' => '00020101021228' . substr(hash('sha256', $reference), 0, 32),
            ],
            'raw' => ['fake' => true, 'reference_id' => $reference],
            'error' => null,
        ];
    }

    public function fetchCharge($reference, $providerChargeId = null)
    {
        if (! isset($this->charges[$reference])) {
            return ['ok' => false, 'charge_id' => $providerChargeId, 'status' => 'unknown',
                    'amount' => null, 'action' => ['type' => 'none', 'value' => ''],
                    'raw' => [], 'error' => 'no such charge'];
        }

        $charge = $this->charges[$reference];

        return [
            'ok' => true,
            'charge_id' => $providerChargeId ?: ('fake_' . substr(hash('sha256', $reference), 0, 20)),
            'status' => $charge['status'],
            'amount' => $charge['amount'],
            'action' => ['type' => 'none', 'value' => ''],
            'raw' => ['fake' => true, 'reference_id' => $reference, 'status' => $charge['status']],
            'error' => null,
        ];
    }

    public function verifyCallback($token)
    {
        $expected = (string) config('wallet.callback_token');

        return $token !== '' && hash_equals($expected, (string) $token);
    }

    // ---- what a test drives it with -------------------------------------

    /** The provider now says this charge was paid, optionally for a different amount. */
    public function markPaid($reference, $amount = null)
    {
        if (! isset($this->charges[$reference])) {
            return;
        }
        $this->charges[$reference]['status'] = 'paid';
        if ($amount !== null) {
            $this->charges[$reference]['amount'] = (float) $amount;
        }
    }

    public function markExpired($reference)
    {
        if (isset($this->charges[$reference])) {
            $this->charges[$reference]['status'] = 'expired';
        }
    }

    public function markFailed($reference)
    {
        if (isset($this->charges[$reference])) {
            $this->charges[$reference]['status'] = 'failed';
        }
    }

    public function failNextCreate($fail = true)
    {
        $this->failCreate = $fail;
    }
}
