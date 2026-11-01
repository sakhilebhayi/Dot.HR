<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public landing page says what Dot.HR does today, links people looking for work to the
 * careers page, carries the original logo, and claims nothing it cannot show.
 */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_describes_the_current_product_and_links_to_open_jobs(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach (['Hire', 'Welcome', 'Work', 'Grow', 'Leave well', 'What Dot.HR will not do.', 'Not built yet:'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringContainsString('href="'.route('jobs.index').'"', $html);
        $this->assertStringContainsString('images/logo-trim.png', $html);
        $this->assertStringContainsString('images/logo-light-trim.png', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_it_drops_the_stale_mvp_claims(): void
    {
        $this->get('/')->assertDontSee('MVP scaffolded')->assertDontSee('43 tests')->assertDontSee('Three entities');
    }
}
