<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W5Helpers;

/**
 * K94 — Saving a product without changes renames its image file:
 * ProductsController::update unlinks every current image and writes the
 * images the edit form sent back under a new random prefix
 * (43177335x.png -> 4586549143177335x.png -> ...), re-encoded to 200x200.
 *
 * public/images/products is shared by every database, live included, so this
 * test never touches it: public_path() is pointed at a temporary directory
 * for the test (the controller builds its paths from public_path()), the
 * redirection is checked before anything is saved, and the directory is
 * removed in tearDown.
 */
class K94ProductImageRenameTest extends DatatestCase
{
    use W5Helpers;

    private $tmpPublic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpPublic = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dt-k94-' . bin2hex(random_bytes(6));
        mkdir($this->tmpPublic . '/images/products', 0777, true);
        $this->app->instance('path.public', $this->tmpPublic);
    }

    protected function tearDown(): void
    {
        if ($this->tmpPublic && is_dir($this->tmpPublic)) {
            foreach (glob($this->tmpPublic . '/images/products/*') as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpPublic . '/images/products');
            @rmdir($this->tmpPublic . '/images');
            @rmdir($this->tmpPublic);
        }
        parent::tearDown();
    }

    /**
     * K94 — A save with no change keeps the product's image name and file.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_saving_a_product_unchanged_keeps_its_image()
    {
        $dir = public_path() . '/images/products/';
        $this->assertStringStartsWith($this->tmpPublic, $dir,
            'safety: the product images must go to the temporary directory, never to the shared public/images/products');

        $name = 'dt-k94-' . bin2hex(random_bytes(4)) . '.png';
        $img = imagecreatetruecolor(320, 240);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 120, 40));
        imagepng($img, $dir . $name);
        imagedestroy($img);
        DB::table('products')->where('id', $this->cement)->update(['image' => $name]);

        $images = $this->actAs($this->owner)->getJson("/api/Products/{$this->cement}/edit")->assertOk()
            ->json('product.images');
        $this->assertSame([$name], array_column($images, 'name'), 'fixture: the edit screen loads the image');

        $this->putJson("/api/Products/{$this->cement}", $this->productBody(['images' => $images]))->assertOk();

        $this->assertSame($name, DB::table('products')->where('id', $this->cement)->value('image'),
            'an unchanged save must keep the image name');
        $this->assertFileExists($dir . $name, 'an unchanged save must keep the image file');
        $this->assertSame([$name], array_map('basename', glob($dir . '*')),
            'an unchanged save must not write a renamed copy');
    }
}
