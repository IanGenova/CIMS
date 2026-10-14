<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The language lines people actually read (DATATEST K69/K90).
 *
 * resources/lang was missing, so a failed sign-in said "auth.failed" and a
 * missing field said "validation.required": the key, not a sentence.
 */
class AuthLanguageTest extends TestCase
{
    /** @test */
    public function sign_in_messages_are_sentences()
    {
        foreach (['auth.failed', 'auth.password', 'auth.throttle'] as $key) {
            $this->assertNotSame($key, trans($key), "$key has no English line");
        }
    }

    /** @test */
    public function password_reset_lines_exist()
    {
        foreach (['passwords.reset', 'passwords.user'] as $key) {
            $this->assertNotSame($key, trans($key), "$key has no English line");
        }
    }
}
