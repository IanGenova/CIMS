<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/** The pager's labels are words, not keys (DATATEST K69: resources/lang was missing). */
class PaginationLanguageTest extends TestCase
{
    /** @test */
    public function test_the_pager_labels_exist()
    {
        foreach (['pagination.next', 'pagination.previous'] as $key) {
            $this->assertNotSame($key, trans($key), "$key has no English line");
        }
    }

    /** @test */
    public function test_a_rendered_pager_shows_words()
    {
        $pager = new LengthAwarePaginator(range(1, 10), 30, 10, 2, ['path' => '/api/products']);

        $html = $pager->links()->toHtml();

        $this->assertStringNotContainsString('pagination.next', $html, 'the pager printed its key');
        $this->assertStringNotContainsString('pagination.previous', $html, 'the pager printed its key');
    }
}
