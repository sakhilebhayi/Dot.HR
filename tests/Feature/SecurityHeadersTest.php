<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * R-32 Phase 2 baseline headers, and since the follow-up an ENFORCED nonce-based
 * Content-Security-Policy plus HSTS, the shape InfoDot runs live.
 */
class SecurityHeadersTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    public function test_every_response_carries_the_baseline_headers(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $this->assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'));
    }

    public function test_the_policy_is_enforced_with_a_nonce_and_no_inline_script_allowance(): void
    {
        $response = $this->get('/');
        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'), 'enforced, never both at once');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9]+)'/", $policy);
        $this->assertStringContainsString("script-src-attr 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString('report-uri /csp-report', $policy);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);

        // Every inline script on the page carries the nonce the header announces.
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $m);
        preg_match_all('/<script(?![^>]*\bsrc=)[^>]*>/', $response->getContent(), $inline);
        $this->assertNotEmpty($inline[0]);
        foreach ($inline[0] as $tag) {
            $this->assertStringContainsString('nonce="'.$m[1].'"', $tag);
        }

        // A new nonce for every request.
        $this->assertStringNotContainsString($m[1], (string) $this->get('/')->headers->get('Content-Security-Policy'));
    }

    public function test_the_signed_in_shell_has_only_nonce_marked_scripts(): void
    {
        $owner = $this->owner();
        $response = $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $response->headers->get('Content-Security-Policy'), $m);
        preg_match_all('/<script\b[^>]*>/', $response->getContent(), $tags);
        $this->assertNotEmpty($tags[0]);
        foreach ($tags[0] as $tag) {
            // Same-origin module scripts are allowed by 'self'; anything inline must carry the nonce.
            if (! str_contains($tag, 'src=')) {
                $this->assertStringContainsString('nonce="'.$m[1].'"', $tag);
            }
        }
        $this->assertDoesNotMatchRegularExpression('/\son(click|submit|change|input|load)=/', $response->getContent());
    }

    public function test_every_outside_image_host_the_views_use_is_allowed(): void
    {
        $policy = (string) $this->get('/')->headers->get('Content-Security-Policy');
        preg_match('/img-src ([^;]+)/', $policy, $img);

        // Mail templates are read in mail clients, not under this policy.
        $hosts = collect(File::allFiles(resource_path('views')))
            ->reject(fn ($file) => str_starts_with($file->getRelativePathname(), 'vendor/mail'))
            ->flatMap(fn ($file) => preg_match_all('/<img[^>]+src="(https:\/\/[^\/"]+)/', $file->getContents(), $m) ? $m[1] : [])
            ->unique()->values();

        $this->assertNotEmpty($hosts, 'the landing photograph is an outside image');
        foreach ($hosts as $host) {
            $this->assertStringContainsString($host, $img[1]);
        }
    }

    public function test_no_view_uses_an_inline_event_handler(): void
    {
        // script-src-attr 'none' means an onclick= would silently do nothing in the browser.
        $offenders = collect(File::allFiles(resource_path('views')))
            ->filter(fn ($file) => preg_match('/\son[a-z]+\s*=\s*["\']/', $file->getContents()))
            ->map(fn ($file) => $file->getRelativePathname())
            ->values()->all();

        $this->assertSame([], $offenders);
    }

    public function test_report_only_is_a_switch_not_a_deploy(): void
    {
        config(['security.csp_report_only' => true]);
        $response = $this->get('/');

        $this->assertTrue($response->headers->has('Content-Security-Policy-Report-Only'));
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
    }

    public function test_hsts_on_https_only_and_switchable(): void
    {
        $this->get('/')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=15552000');

        config(['security.hsts_seconds' => 0]);
        $this->get('https://localhost/')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_violation_reports_are_accepted_without_a_session_and_logged_by_host_only(): void
    {
        Cache::flush();
        Log::spy();

        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode([
            'csp-report' => ['effective-directive' => 'script-src-elem', 'blocked-uri' => 'https://evil.example/x.js?token=secret'],
        ]))->assertNoContent();

        Log::shouldHaveReceived('info')->once()->with('csp.violation', ['directive' => 'script-src-elem', 'blocked_host' => 'evil.example']);
    }
}
