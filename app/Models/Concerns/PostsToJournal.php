<?php

namespace App\Models\Concerns;

use App\utils\LedgerPoster;
use App\utils\LedgerService;

/**
 * Keeps a payment or a return in step with the journal (DATATEST K27).
 *
 * The model names its kind in LEDGER_KIND (a LedgerPoster kind). A return is
 * posted once it is saved in a posting status. A payment is posted when it is
 * made; when it is edited or deleted, what was booked for it is taken back,
 * and an edited payment is booked again at its new amount.
 *
 * On the model rather than in the controllers, so a path nobody remembered
 * to wire still reaches the books. Payments deleted in bulk by a query (a
 * whole document deleted) are covered by LedgerService::reverseDocument.
 */
trait PostsToJournal
{
    protected static function bootPostsToJournal()
    {
        $kind = static::LEDGER_KIND;
        $isPayment = isset(LedgerService::PAYMENTS[$kind]);

        static::saved(function ($model) use ($kind, $isPayment) {
            if ($isPayment) {
                if ($model->wasRecentlyCreated) {
                    LedgerPoster::postSoon($kind, $model->id);

                    return;
                }
                if ($model->wasChanged('montant') || ($model->wasChanged('deleted_at') && $model->deleted_at)) {
                    LedgerService::unbookPayment($kind, $model->id, $model->getOriginal('montant'));
                    if (! $model->deleted_at) {
                        LedgerPoster::postSoon($kind, $model->id);
                    }
                }

                return;
            }
            if (in_array($model->statut, ['received', 'completed'], true)) {
                LedgerPoster::postSoon($kind, $model->id);
            }
        });

        if ($isPayment) {
            static::deleted(function ($model) use ($kind) {
                LedgerService::unbookPayment($kind, $model->id, $model->getOriginal('montant'));
            });
        }
    }
}
