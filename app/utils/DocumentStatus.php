<?php

namespace App\utils;

use Illuminate\Validation\ValidationException;

/**
 * The statuses each document may be saved with (DATATEST K51).
 *
 * Any text was stored ("banana" on five document types). Nothing moved stock
 * for it, but every list, report and rule that asks "is this received?" was
 * left guessing. These are the values the screens offer; "voided" is reached
 * only through VoidService, never by saving.
 */
class DocumentStatus
{
    const ALLOWED = [
        'sale' => ['completed', 'pending', 'ordered'],
        'purchase' => ['ordered', 'pending', 'received'],
        'transfer' => ['completed', 'sent', 'pending'],
        'sale_return' => ['received', 'pending'],
        'purchase_return' => ['completed', 'pending'],
        'quotation' => ['pending', 'sent'],
    ];

    public static function assert($document, $statut)
    {
        $allowed = self::ALLOWED[$document];
        if (! in_array($statut, $allowed, true)) {
            throw ValidationException::withMessages(['statut' => [
                'The status must be one of: ' . implode(', ', $allowed) . '.',
            ]]);
        }
    }
}
