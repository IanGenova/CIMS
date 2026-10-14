<?php

namespace Tests\Datatest;

use Tests\Datatest\Concerns\W3Helpers;

/**
 * K69 — Validation errors show raw keys such as `validation.gt.numeric`
 * because resources/lang is missing.
 */
class K69ValidationMessagesTest extends DatatestCase
{
    use W3Helpers;

    private function assertHumanMessages($response, $field)
    {
        $response->assertStatus(422);
        $body = (array) $response->json();
        $messages = (array) ($body['errors'][$field] ?? $body[$field] ?? []); // keys hold dots: read them whole
        $this->assertNotEmpty($messages, "fixture: $field is reported");
        foreach ($messages as $message) {
            $this->assertStringStartsNotWith('validation.', (string) $message, "the user must read a sentence, not the key \"$message\"");
        }
    }

    /**
     * K69 — A POS line of quantity 0 is answered with "validation.gt.numeric".
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_zero_quantity_is_explained_in_words()
    {
        $response = $this->actAs($this->owner)->pos([$this->line(0)]);

        $this->assertHumanMessages($response, 'details.0.quantity');
    }

    /**
     * K69 — A customer without an address is answered with "validation.required".
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_missing_customer_address_is_explained_in_words()
    {
        $body = $this->clientBody();
        unset($body['adresse']);

        $response = $this->actAs($this->owner)->postJson('/api/clients', $body);

        $this->assertHumanMessages($response, 'adresse');
    }
}
