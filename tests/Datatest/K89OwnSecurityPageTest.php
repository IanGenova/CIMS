<?php

namespace Tests\Datatest;

use Tests\Datatest\Concerns\W5Helpers;

/**
 * K89 — Staff cannot reach their own Security page (two-factor setup, own
 * sessions, own password): on load the page also asks for the owner-only
 * GET security/policy, which answers 403, and the app's 403 handler sends the
 * whole page to "not authorized". Every non-owner ends there; the 2FA setup
 * exists only on that page.
 *
 * What the page requests on load lives in security.vue, so the test reads the
 * page's created() hook, follows each unconditional `this.Method()` call to the
 * axios.get URLs in that method, and asks the API for each one as staff. It is
 * green once every request the page makes for everybody succeeds for staff --
 * whether the repair opens the read to staff or makes the page ask for the
 * policy only when the user may read it (a call inside an `if` is not counted).
 */
class K89OwnSecurityPageTest extends DatatestCase
{
    use W5Helpers;

    /** The body of the method or hook whose header starts at $start (brace-matched). */
    private function block($src, $start)
    {
        $open = strpos($src, '{', $start);
        $depth = 0;
        for ($i = $open; $i < strlen($src); $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}' && --$depth === 0) {
                return substr($src, $open + 1, $i - $open - 1);
            }
        }

        return '';
    }

    /** The GET URLs security.vue requests unconditionally on load. */
    private function loadRequests()
    {
        $src = file_get_contents(resource_path('src/views/app/pages/settings/security.vue'));
        $this->assertTrue((bool) preg_match('/\n\s*created\s*\(\)\s*\{/', $src, $m, PREG_OFFSET_CAPTURE),
            'fixture: security.vue has a created() hook');
        $created = $this->block($src, $m[0][1]);

        // Only calls at the top level of created(): one inside an `if` is conditional.
        $calls = [];
        $depth = 0;
        foreach (preg_split('/\R/', $created) as $line) {
            if ($depth === 0 && preg_match('/^\s*this\.(\w+)\(\)\s*;/', $line, $c)) {
                $calls[] = $c[1];
            }
            $depth += substr_count($line, '{') - substr_count($line, '}');
        }

        $urls = [];
        foreach ($calls as $method) {
            if (preg_match('/\n\s*' . preg_quote($method, '/') . '\s*\([^)]*\)\s*\{/', $src, $mm, PREG_OFFSET_CAPTURE)) {
                preg_match_all('/axios\s*\.get\(\s*["\']([^"\']+)["\']/', $this->block($src, $mm[0][1]), $g);
                $urls = array_merge($urls, $g[1]);
            }
        }

        return array_values(array_unique($urls));
    }

    public function staff()
    {
        return ['cashier' => ['cashier'], 'stockman' => ['stockman'], 'no permissions' => ['nobody']];
    }

    /**
     * K89 — Every request the Security page makes on load succeeds for a staff
     * member, so the page opens and they can set up two-factor.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     *
     * @dataProvider staff
     */
    public function test_staff_can_load_their_own_security_page($who)
    {
        $urls = $this->loadRequests();
        $this->assertContains('security/me', $urls, 'fixture: the page loads the user\'s own security on open');

        $failed = [];
        foreach ($urls as $url) {
            $status = $this->actAs($this->{$who})->getJson('/api/' . $url)->getStatusCode();
            if ($status !== 200) {
                $failed[] = "GET $url -> HTTP $status";
            }
        }

        $this->assertSame([], $failed, "the Security page's own load requests must succeed for the $who "
            . '(a 403 sends the whole page to not_authorize)');
    }
}
