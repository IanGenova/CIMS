<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

class WarehouseSiteIdentityTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    /** @test */
    public function company_locations_have_unique_codes_and_explicit_types()
    {
        $this->actAsUserWith(['warehouse']);

        $this->postJson('/api/warehouses', [
            'name' => 'Central Depot', 'code' => 'central',
            'site_type' => 'central_warehouse', 'mobile' => '0917',
            'country' => 'PH', 'city' => 'Manila', 'zip' => '1000',
        ])->assertSuccessful();
        $central = DB::table('warehouses')->where('code', 'CENTRAL')->first();
        $this->assertNotNull($central);
        $this->assertSame('central_warehouse', $central->site_type);

        $this->postJson('/api/warehouses', [
            'name' => 'Duplicate code', 'code' => 'CeNtRaL',
            'site_type' => 'branch', 'mobile' => '0917',
            'country' => 'PH', 'city' => 'Manila',
        ])->assertStatus(422);

        $this->postJson('/api/warehouses', [
            'name' => 'Branch One', 'site_type' => 'branch',
            'mobile' => '0917', 'country' => 'PH', 'city' => 'Quezon City',
        ])->assertSuccessful();
        $branch = DB::table('warehouses')->where('name', 'Branch One')->first();
        $this->assertSame('branch', $branch->site_type);
        $this->assertNotNull($branch->code);
        $this->assertNotSame($central->code, $branch->code);
    }
}
