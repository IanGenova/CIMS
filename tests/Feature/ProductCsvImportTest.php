<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

class ProductCsvImportTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    private function setupCatalog(): void
    {
        $this->actAsUserWith(['product_import']);
        DB::table('categories')->insert(['code' => 'HW', 'name' => 'Hardware']);
        DB::table('brands')->insert(['name' => 'Acme']);
        DB::table('units')->insert(['name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1]);
        DB::table('warehouses')->insert(['name' => 'Main Warehouse']);
    }

    private function upload(string $csv, bool $preview = false)
    {
        return $this->withHeaders(['Accept' => 'application/json'])->post('/api/Products/import/csv', [
            'products' => UploadedFile::fake()->createWithContent('products.csv', $csv),
            'preview' => $preview ? '1' : '0',
        ]);
    }

    public function test_preview_writes_nothing_and_commit_creates_products_and_stock_rows(): void
    {
        $this->setupCatalog();
        $csv = "name,code,category,brand,cost,price,unit,stock_alert,note\n"
            . "Hammer,HW-IMPORT-1,Hardware,Acme,100,150,pc,5,Steel handle\n";

        $this->upload($csv, true)->assertOk()->assertJsonPath('total_rows', 1)
            ->assertJsonPath('valid_rows', 1)->assertJsonPath('error_count', 0);
        $this->assertSame(0, DB::table('products')->where('code', 'HW-IMPORT-1')->count());

        $this->upload($csv)->assertOk()->assertJsonPath('imported', 1);
        $product = DB::table('products')->where('code', 'HW-IMPORT-1')->first();
        $this->assertNotNull($product);
        $this->assertEquals(100, $product->cost);
        $this->assertEquals(150, $product->price);
        $this->assertSame(1, DB::table('product_warehouse')->where('product_id', $product->id)->count());
    }

    public function test_duplicate_or_bad_row_blocks_entire_import_with_row_errors(): void
    {
        $this->setupCatalog();
        $csv = "name,code,category,brand,cost,price,unit,stock_alert,note\n"
            . "Hammer,HW-IMPORT-2,Hardware,Acme,100,150,pc,0,\n"
            . "Saw,HW-IMPORT-2,Hardware,Acme,40,50,pc,0,\n";
        $this->upload($csv, true)->assertOk()->assertJsonPath('total_rows', 2)
            ->assertJsonPath('error_count', 1)->assertJsonPath('errors.0.line', 3);
        $this->upload($csv)->assertStatus(422);
        $this->assertSame(0, DB::table('products')->where('code', 'HW-IMPORT-2')->count());

        $invalid = "name,code,category,brand,cost,price,unit\n"
            . "Hammer,HW-IMPORT-3,Hardware,Acme,100,150,pc\n"
            . "Saw,HW-IMPORT-4,Unknown,Acme,40,no-price,missing-unit\n";
        $this->upload($invalid)->assertStatus(422)->assertJsonPath('total_rows', 2);
        $this->assertSame(0, DB::table('products')->whereIn('code', ['HW-IMPORT-3', 'HW-IMPORT-4'])->count());
    }

    public function test_missing_columns_and_existing_codes_are_reported(): void
    {
        $this->setupCatalog();
        $this->upload("name,code\nHammer,H-1\n", true)->assertOk()
            ->assertJsonPath('errors.0.line', 1);
        $valid = "name,code,category,cost,price,unit\nHammer,HW-IMPORT-5,Hardware,100,150,pc\n";
        $this->upload($valid)->assertOk();
        $this->upload($valid, true)->assertOk()->assertJsonPath('errors.0.line', 2);
        $this->upload($valid)->assertStatus(422);
        $this->assertSame(1, DB::table('products')->where('code', 'HW-IMPORT-5')->count());
    }
}
