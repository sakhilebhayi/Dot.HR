<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The Register's .rg-form-row stacks into one column on phones. An inline grid-template-columns
 * on the same element overrides that media query and leaves fields cramped side by side (it
 * happened twice: case and plan forms, then candidate search). Use a modifier class instead
 * (.rg-form-row--lead, --objective, --3).
 */
class FormRowsStackOnPhonesTest extends TestCase
{
    public function test_no_form_row_sets_its_columns_inline(): void
    {
        $offenders = collect(File::allFiles(resource_path('views')))
            ->filter(fn ($file) => preg_match('/class="[^"]*\brg-form-row\b[^"]*"[^>]*style="[^"]*grid-template-columns/', $file->getContents()))
            ->map(fn ($file) => $file->getRelativePathname())->values()->all();

        $this->assertSame([], $offenders);
    }
}
