<?php

namespace App\utils\Wallet;

/**
 * What a payment provider has to be able to do.
 *
 * Deliberately three methods and no more. Everything else about taking a wallet
 * payment -- deciding whether it is really paid, what to do when the amount is
 * wrong, when to give up on it, how it reaches a sale -- belongs to the service
 * and is the same whichever provider is behind this. A provider that needs a
 * fourth method here is usually a sign that provider-specific behaviour is
 * leaking into the decisions.
 */
interface WalletGateway
{
    /**
     * Ask the provider to create a charge.
     *
     * Returns:
     *   [
     *     'ok' => bool,
     *     'charge_id' => string|null,     the provider's own identifier
     *     'status' => string,             normalised: pending|paid|failed|expired|cancelled
     *     'action' => ['type' => 'qr'|'redirect'|'none', 'value' => string],
     *     'raw' => array,                 what the provider actually sent back
     *     'error' => string|null,
     *   ]
     */
    public function createCharge(array $params);

    /**
     * Ask the provider what became of a charge.
     *
     * This is the only thing in the system permitted to decide that money
     * arrived. Returns the same shape as createCharge, plus 'amount' as the
     * provider reports it -- which the service checks against what it asked for.
     */
    public function fetchCharge($reference, $providerChargeId = null);

    /**
     * Is this callback really from the provider?
     *
     * A true answer means the request is authentic. It does not mean the payment
     * succeeded -- that still has to be asked with fetchCharge, because a
     * callback body is a claim and a fetch is an answer.
     */
    public function verifyCallback($token);
}
