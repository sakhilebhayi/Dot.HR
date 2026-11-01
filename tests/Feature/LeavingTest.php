<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ExitInterview;
use App\Models\LifecycleChecklist;
use App\Models\Resignation;
use App\Models\ServiceCertificate;
use App\Models\User;
use App\Services\Hr\Leaving;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * Leaving well (brief §15): written notice with the BCEA minimum worked out, receipt by HR
 * starting offboarding, withdrawal only by agreement, voluntary and confidential exit
 * interviews with anonymous patterns above a floor, and the certificate of service.
 */
class LeavingTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    /** @return object{owner: User, manager: User, staff: User, other: User, employee: Employee} */
    private function team(string $started = '-2 years'): object
    {
        $owner = $this->owner();
        $manager = $this->memberOf($owner, 'editor');
        $staff = $this->memberOf($owner, 'viewer');
        $other = $this->memberOf($owner, 'viewer');
        $employee = $this->employeeIn($owner->currentTeam, $staff, ['first_name' => 'Thandi', 'last_name' => 'Mokoena', 'start_date' => now()->modify($started)->toDateString()]);
        $this->employeeIn($owner->currentTeam, $other);

        return (object) compact('owner', 'manager', 'staff', 'other', 'employee');
    }

    private function resign(object $t, string $lastDay): Resignation
    {
        $this->actingAs($t->staff)->post(route('my-work.resign'), ['last_day' => $lastDay, 'reason' => 'new_role', 'message' => 'Thank you for three good years.', 'confirm' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();

        return Resignation::withoutTeamScope(fn () => Resignation::query()->latest('id')->firstOrFail());
    }

    public function test_minimum_notice_follows_length_of_service(): void
    {
        $today = Carbon::parse('2026-11-01');
        $this->assertSame(7, Resignation::minimumNoticeDays(Carbon::parse('2026-06-01'), $today));
        $this->assertSame(14, Resignation::minimumNoticeDays(Carbon::parse('2026-03-01'), $today));
        $this->assertSame(28, Resignation::minimumNoticeDays(Carbon::parse('2025-11-01'), $today));
        $this->assertSame(28, Resignation::minimumNoticeDays(null, $today));
    }

    public function test_notice_is_given_in_writing_and_hr_acknowledges_it(): void
    {
        $t = $this->team();
        $this->actingAs($t->staff)->post(route('my-work.resign'), ['last_day' => now()->addWeeks(4)->toDateString()])->assertSessionHasErrors('confirm');
        $this->actingAs($t->staff)->post(route('my-work.resign'), ['last_day' => now()->subDay()->toDateString(), 'confirm' => 1])->assertSessionHasErrors('last_day');

        $resignation = $this->resign($t, now()->addWeeks(2)->toDateString());
        $this->assertSame(28, $resignation->minimum_notice_days);
        $this->assertStringNotContainsString('three good years', (string) DB::table('resignations')->value('message'));
        $notice = DB::table('notifications')->where('notifiable_id', $t->owner->id)->value('data');
        $this->assertStringNotContainsString('Thandi', $notice);

        $this->actingAs($t->staff)->post(route('my-work.resign'), ['last_day' => now()->addWeeks(6)->toDateString(), 'confirm' => 1])->assertSessionHasErrors('last_day');
        $this->actingAs($t->manager)->get(route('leaving.index'))->assertForbidden();
        $this->actingAs($t->owner)->get(route('leaving.index'))->assertOk()->assertSee('Thandi Mokoena resigned')->assertSee('Shorter than the minimum notice')->assertSee('three good years');

        $agreed = now()->addWeeks(4)->toDateString();
        $this->actingAs($t->owner)->post(route('leaving.acknowledge', $resignation), ['agreed_last_day' => $agreed, 'offer_interview' => 1])->assertRedirect();

        $resignation->refresh();
        $this->assertSame('acknowledged', $resignation->status);
        $checklist = LifecycleChecklist::withoutTeamScope(fn () => LifecycleChecklist::query()->findOrFail($resignation->lifecycle_checklist_id));
        $this->assertSame(['offboarding', 'resignation', $agreed], [$checklist->process, $checklist->reason, $checklist->last_day->toDateString()]);
        $this->assertTrue(ExitInterview::withoutTeamScope(fn () => ExitInterview::query()->where('employee_id', $t->employee->id)->exists()));
        $this->actingAs($t->staff)->get(route('my-work.index'))->assertSee(Carbon::parse($agreed)->format('l j F Y'))->assertSee('as agreed with HR')->assertSee('Tell us about your time here');
    }

    public function test_a_resignation_is_withdrawn_only_by_agreement(): void
    {
        $t = $this->team();
        $resignation = $this->resign($t, now()->addWeeks(4)->toDateString());
        $this->actingAs($t->owner)->post(route('leaving.acknowledge', $resignation))->assertRedirect();

        $this->actingAs($t->other)->post(route('my-work.resignation.withdraw', $resignation), ['note' => 'Not mine to withdraw.'])->assertNotFound();
        $this->actingAs($t->staff)->post(route('my-work.resignation.withdraw', $resignation), ['note' => 'The new offer fell through.'])->assertRedirect();
        $this->assertSame('acknowledged', $resignation->fresh()->status);

        $this->actingAs($t->owner)->post(route('leaving.withdrawal', [$resignation, 'agree']))->assertRedirect();
        $this->assertSame('withdrawn', $resignation->fresh()->status);
        $this->assertSame('cancelled', LifecycleChecklist::withoutTeamScope(fn () => LifecycleChecklist::query()->findOrFail($resignation->fresh()->lifecycle_checklist_id))->status);
        $this->actingAs($t->staff)->get(route('my-work.index'))->assertSee('Resign');
    }

    public function test_exit_interviews_are_voluntary_confidential_and_only_summarised_anonymously(): void
    {
        $t = $this->team();
        $interview = app(Leaving::class)->offerInterview($t->employee, $t->owner);

        $this->actingAs($t->other)->get(route('my-work.exit-interview', $interview))->assertNotFound();
        $this->actingAs($t->staff)->post(route('my-work.exit-interview.answer', $interview), [
            'reasons' => ['growth', 'management'], 'answers' => ['improve' => 'Weekly one-to-ones would have helped.'],
            'would_return' => 'unsure', 'share_anonymously' => 1,
        ])->assertRedirect();

        $this->assertStringNotContainsString('one-to-ones', (string) DB::table('exit_interviews')->value('answers'));
        $this->actingAs($t->manager)->get(route('leaving.interview', $interview))->assertForbidden();
        $this->actingAs($t->owner)->get(route('leaving.interview', $interview))->assertOk()->assertSee('Weekly one-to-ones')->assertSee('How I was managed');

        // Below the anonymity floor there are no patterns at all.
        $this->actingAs($t->owner)->get(route('leaving.index'))->assertSee('Shown once 5 people');
        $this->assertNull(app(Leaving::class)->patterns($t->owner->currentTeam->id));

        foreach (range(1, 4) as $i) {
            $person = $this->employeeIn($t->owner->currentTeam);
            $x = app(Leaving::class)->offerInterview($person, $t->owner);
            app(Leaving::class)->completeInterview($x, 'form', ['growth'], [], 'no', true);
        }
        $patterns = app(Leaving::class)->patterns($t->owner->currentTeam->id);
        $this->assertSame(5, $patterns['count']);
        $this->assertSame(5, $patterns['reasons']['growth']);
        $this->actingAs($t->owner)->get(route('leaving.index'))->assertSee('From 5 people')->assertDontSee('one-to-ones');
    }

    public function test_the_certificate_of_service_states_the_reason_only_when_asked(): void
    {
        $t = $this->team();
        $base = [
            'employer_name' => 'Mokoena Stores', 'employer_address' => '12 Main Road, Mahikeng, 2745', 'job_title' => 'Stock controller',
            'start_date' => '2024-11-01', 'end_date' => now()->toDateString(), 'remuneration' => 'R18 500 per month',
        ];
        $this->actingAs($t->owner)->post(route('leaving.certificate.store', $t->employee), $base + ['reason_requested' => 1])->assertSessionHasErrors('reason');
        $this->actingAs($t->manager)->post(route('leaving.certificate.store', $t->employee), $base)->assertForbidden();
        $this->actingAs($t->owner)->post(route('leaving.certificate.store', $t->employee), $base + ['reason' => 'Resignation'])->assertRedirect();

        $certificate = ServiceCertificate::withoutTeamScope(fn () => ServiceCertificate::query()->sole());
        $this->assertNull($certificate->reason, 'not stated unless they asked');
        $this->assertStringNotContainsString('18 500', (string) DB::table('service_certificates')->value('remuneration'));

        $page = $this->actingAs($t->staff)->get(route('my-work.certificate', $certificate))->assertOk();
        $page->assertSee('Certificate of service')->assertSee('Stock controller')->assertSee('R18 500 per month')->assertSee('section 42')->assertSee('data-print', false);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/', $page->getContent());
        $this->actingAs($t->other)->get(route('my-work.certificate', $certificate))->assertNotFound();

        $mine = $this->actingAs($t->staff)->get(route('my-work.data'))->assertOk()->json();
        $this->assertSame('R18 500 per month', $mine['certificates_of_service'][0]['remuneration']);
    }

    public function test_answered_exit_interviews_leave_after_the_retention_period(): void
    {
        $t = $this->team();
        $old = app(Leaving::class)->offerInterview($t->employee, $t->owner);
        app(Leaving::class)->declineInterview($old);
        $old->forceFill(['declined_at' => now()->subDays(365 * 5 + 1)])->save();

        $this->artisan('hr:retention')->assertSuccessful();
        $this->assertDatabaseMissing('exit_interviews', ['id' => $old->id]);
    }
}
