<?php

namespace Tests\Feature;

use App\Models\HrAuditLog;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\Intelligence\InsightsExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * Insights as CSV: the page's own figures in one tidy table, HR administrators only, audited,
 * and safe to open in a spreadsheet (no cell can run as a formula).
 */
class InsightsExportTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    /** @return list<list<string>> */
    private function csv(string $body): array
    {
        $this->assertStringStartsWith("\u{FEFF}", $body, 'UTF-8 marker for spreadsheet apps');
        $lines = array_filter(explode("\r\n", substr($body, 3)));

        return array_values(array_map(fn ($l) => str_getcsv($l, ',', '"', ''), $lines));
    }

    private function role(User $owner, string $title): void
    {
        $this->actingAs($owner)->post(route('hiring.store'), [
            'title' => $title, 'description' => 'Keep the books of a growing wholesaler.', 'skills' => 'Pastel Partner',
            'work_type' => 'full_time', 'arrangement' => 'on_site', 'location' => 'Mahikeng', 'pay_basis' => 'salary',
            'pay_min' => '18000', 'pay_max' => '22000', 'currency' => 'ZAR', 'openings' => 1, 'closes_on' => now()->addWeeks(2)->toDateString(),
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('hiring.transition', [Vacancy::query()->latest('id')->firstOrFail(), 'publish']))->assertRedirect();
    }

    public function test_hr_admins_download_the_page_as_one_tidy_table(): void
    {
        $owner = $this->owner();
        foreach (range(1, 3) as $i) {
            $this->employeeIn($owner->currentTeam, null, ['employment_type' => 'full_time']);
        }

        $response = $this->actingAs($owner)->get(route('insights.export'))->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename=dot-hr-insights-', (string) $response->headers->get('Content-Disposition'));

        $rows = $this->csv($response->streamedContent());
        $this->assertSame(InsightsExport::HEADER, $rows[0]);
        $this->assertContains(['People', 'Everyone', 'Working here', '3', 'Now'], $rows);
        $this->assertContains(['People', 'full time', 'Working here', '3', 'Now'], $rows);
        $this->assertEqualsCanonicalizing(['Section', 'People', 'Hiring', 'Leave', 'Training', 'Certificates', 'Contractors'], array_values(array_unique(array_column($rows, 0))));

        $log = HrAuditLog::withoutTeamScope(fn () => HrAuditLog::query()->where('action', 'insights.exported')->sole());
        $this->assertSame('all', $log->changes['section']);
    }

    public function test_one_section_at_a_time_and_only_for_hr_admins(): void
    {
        $owner = $this->owner();
        $manager = $this->memberOf($owner, 'editor');
        $staff = $this->memberOf($owner, 'viewer');
        $this->role($owner, 'Bookkeeper');

        $rows = $this->csv($this->actingAs($owner)->get(route('insights.export', ['section' => 'hiring']))->assertOk()->streamedContent());
        $this->assertSame(['Section', 'Hiring'], array_values(array_unique(array_column($rows, 0))));
        $this->assertContains(['Hiring', 'All roles', 'Open roles', '1', 'Now'], $rows);
        $this->assertContains(['Hiring', 'Bookkeeper', 'Applications', '0', 'Since published'], $rows);

        $this->actingAs($owner)->get(route('insights.export', ['section' => 'salaries']))->assertNotFound();
        $this->actingAs($manager)->get(route('insights.export'))->assertForbidden();
        $this->actingAs($staff)->get(route('insights.export'))->assertForbidden();
    }

    public function test_no_cell_can_run_as_a_formula(): void
    {
        $owner = $this->owner();
        $this->role($owner, '=HYPERLINK("http://evil.example","Pay")');

        $body = $this->actingAs($owner)->get(route('insights.export', ['section' => 'hiring']))->streamedContent();
        $titles = array_column(array_filter($this->csv($body), fn ($r) => $r[2] === 'Applications' && $r[1] !== 'All roles'), 1);

        $this->assertNotEmpty($titles);
        foreach ($titles as $title) {
            $this->assertStringStartsWith("'=", $title);
        }
        $this->assertSame("'-1,5", trim(InsightsExport::line(['-1,5']), "\"\r\n"));
    }

    public function test_the_page_offers_the_downloads(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->get(route('insights.index'))->assertOk()
            ->assertSee('Download everything as CSV')
            ->assertSee(route('insights.export', ['section' => 'leave']), false);
    }
}
