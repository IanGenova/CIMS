<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W5Helpers;

/**
 * K90 — A failed sign-in shows the raw translation key "auth.failed": there is
 * no resources/lang (nor lang/) folder, so trans('auth.failed') and every
 * built-in validation message come back as their keys. Staff cannot tell a
 * typo from an inactive account.
 */
class K90SignInMessageTest extends DatatestCase
{
    use W5Helpers;

    private function assertReadable($message, $context)
    {
        $this->assertIsString($message, "$context: the sign-in must say why it failed");
        $this->assertDoesNotMatchRegularExpression('/^[a-z_]+(\.[a-z_]+)+$/', $message,
            "$context: the message must be a sentence, not the translation key \"$message\"");
    }

    public function failedSignIns()
    {
        return [
            'wrong password' => ['owner', 'not-the-password'],
            'unknown e-mail' => [null, 'Str0ngEnough'],
            'inactive account' => ['inactive', 'Str0ngEnough'],
        ];
    }

    /**
     * K90 — A failed sign-in answers "auth.failed" instead of a readable message.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     *
     * @dataProvider failedSignIns
     */
    public function test_a_failed_sign_in_gives_a_readable_message($who, $password)
    {
        $email = 'nobody-here@example.invalid';
        if ($who === 'owner') {
            $email = $this->owner->email;
        } elseif ($who === 'inactive') {
            DB::table('users')->where('id', $this->cashier->id)->update(['statut' => 0]);
            $email = $this->cashier->email;
        }

        $response = $this->postJson('/login', ['email' => $email, 'password' => $password]);

        $response->assertStatus(422);
        $this->assertReadable($response->json('errors.email.0'), $who ?: 'unknown e-mail');
    }

    /**
     * K90 — The sign-in form's own validation (empty e-mail) answers
     * "validation.required": the same missing language files.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_an_empty_sign_in_form_gives_a_readable_message()
    {
        $response = $this->postJson('/login', ['email' => '', 'password' => '']);

        $response->assertStatus(422);
        $this->assertReadable($response->json('errors.email.0'), 'empty e-mail');
    }
}
