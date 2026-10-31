<?php

namespace Tests\Feature;

use Tests\TestCase;

/** R-32 Phase 2: the fleet's baseline headers, previously absent from Dot.HR entirely. */
class SecurityHeadersTest extends TestCase
{
    public function test_every_response_carries_the_baseline_headers(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $this->assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'));
        $this->assertStringContainsString("frame-ancestors 'self'", (string) $response->headers->get('Content-Security-Policy-Report-Only'));

        // Report-only until the reports say enforcing is safe; never both at once.
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
    }

    public function test_hsts_is_off_until_configured(): void
    {
        $this->get('/')->assertHeaderMissing('Strict-Transport-Security');
    }
}
