<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * The purchase approval limit, on System Settings.
 *
 * Purchases at or above it wait for a second person's approval
 * (PurchaseApprovalController::initialStatusFor). It was set to 10,000 in the
 * database but no screen showed or changed it. Blank switches approval off.
 */
class SettingsControllerApprovalTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    private $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAsUserWith(['setting_system']);
        $this->id = DB::table('settings')->insertGetId(['email' => 'shop@example.invalid', 'CompanyName' => 'Genova Hardware',
            'CompanyPhone' => '0917', 'CompanyAdress' => 'QC', 'purchase_approval_threshold' => 10000]);
    }

    private function save(array $extra)
    {
        return $this->putJson("/api/settings/{$this->id}", array_merge([
            'CompanyName' => 'Genova Hardware', 'email' => 'shop@example.invalid',
            'currency' => 'null', 'client' => 'null', 'warehouse' => 'null', 'default_language' => 'en',
        ], $extra));
    }

    private function limit()
    {
        return DB::table('settings')->where('id', $this->id)->value('purchase_approval_threshold');
    }

    /** @test */
    public function the_screen_is_told_the_current_limit()
    {
        $this->assertEquals(10000, $this->getJson('/api/getSettings')->assertOk()->json('settings.purchase_approval_threshold'));
    }

    /** @test */
    public function the_limit_can_be_changed()
    {
        $this->save(['purchase_approval_threshold' => '25000'])->assertOk();
        $this->assertEquals(25000, $this->limit());
    }

    /** @test */
    public function blank_switches_approval_off()
    {
        $this->save(['purchase_approval_threshold' => ''])->assertOk();
        $this->assertNull($this->limit());
        $this->assertSame('not_required', \App\Http\Controllers\PurchaseApprovalController::initialStatusFor(999999));
    }

    /** @test */
    public function a_negative_limit_is_refused()
    {
        $this->save(['purchase_approval_threshold' => '-5'])->assertStatus(422);
        $this->assertEquals(10000, $this->limit());
    }

    /** @test */
    public function saving_without_the_field_keeps_the_limit()
    {
        $this->save([])->assertOk();
        $this->assertEquals(10000, $this->limit());
    }
}
