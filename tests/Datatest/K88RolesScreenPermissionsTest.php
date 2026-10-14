<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W5Helpers;

/**
 * K88 — The Roles screen cannot show or grant 14 permissions. No API offers
 * the permission list: the create and edit screens hard-code one checkbox per
 * permission name, and 14 names in the `permissions` table have none
 * (goods_receipt_*, stock_count_*, purchases_approve, manufacturing,
 * accounting, audit_view, customer_group, tax_group). A role's grant without a
 * checkbox is invisible on the edit screen and can be neither given nor taken.
 *
 * The screens are .vue files with no API behind the list, so the test compares
 * the `permissions` table (the system's own list, as the fixture seeds it)
 * with the checkbox values in the two screens' source.
 */
class K88RolesScreenPermissionsTest extends DatatestCase
{
    use W5Helpers;

    private function checkboxes($screen)
    {
        $src = file_get_contents(resource_path("src/views/app/pages/settings/permissions/{$screen}.vue"));
        preg_match_all('/\bvalue="([A-Za-z_]+)"/', $src, $m);

        return array_values(array_unique($m[1]));
    }

    public function roleScreens()
    {
        return ['create screen' => ['Create_permission'], 'edit screen' => ['Edit_permission']];
    }

    /**
     * K88 — Every permission in the system has a checkbox on the Roles screen.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     *
     * @dataProvider roleScreens
     */
    public function test_every_permission_can_be_seen_and_granted_on_the_roles_screen($screen)
    {
        $all = DB::table('permissions')->distinct()->pluck('name')->all();
        $this->assertNotEmpty($all, 'fixture: the permissions table is seeded');

        $missing = array_values(array_diff($all, $this->checkboxes($screen)));
        sort($missing);

        $this->assertSame([], $missing, "every permission must have a checkbox on the Roles $screen ("
            . count($missing) . ' have none)');
    }
}
