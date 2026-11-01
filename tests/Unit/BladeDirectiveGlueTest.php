<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Blade compiles `@if`, `@endif` and friends only when the `@` does not follow a letter or digit.
 * Written as `competencies@if (…)`, the directive is left as text and printed on the page — which
 * happened in InfoDot twice and nearly in Dot.HR. This keeps every view free of glued control directives.
 */
class BladeDirectiveGlueTest extends TestCase
{
    public function test_no_view_glues_a_control_directive_to_a_word(): void
    {
        $views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/views'));
        $found = [];

        foreach ($views as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            foreach (file($file->getPathname()) ?: [] as $i => $line) {
                if (preg_match('/[A-Za-z0-9]@(if|elseif|else|endif|foreach|endforeach|forelse|endforelse|unless|endunless|isset|endisset|auth|endauth|guest|endguest|can|endcan)\b/', $line)) {
                    $found[] = str_replace(dirname(__DIR__, 2).'/', '', $file->getPathname()).':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $found, 'Put a space or {{ }} before these directives: '.implode(', ', $found));
    }
}
