<?php

namespace Tests\Feature\Hr;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Support\Hr\SensitiveDataProtector;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

class SensitiveFieldsTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    public function test_reason_phone_and_email_are_encrypted_at_rest_and_readable_through_the_model(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam, null, ['email' => 'Plain.Person@Example.test', 'phone' => '+27 82 555 1212']);
        $leave = $this->leaveFor($employee, '2026-03-09', '2026-03-10', ['reason' => 'a very private reason']);

        $rawEmployee = DB::table('employees')->where('id', $employee->id)->first();
        $rawLeave = DB::table('leave_requests')->where('id', $leave->id)->first();

        $this->assertStringNotContainsString('Plain.Person', $rawEmployee->email);
        $this->assertStringNotContainsString('555 1212', $rawEmployee->phone);
        $this->assertStringNotContainsString('private reason', $rawLeave->reason);
        $this->assertSame('a very private reason', Crypt::decryptString($rawLeave->reason));

        $this->actingAs($owner);
        $this->assertSame('Plain.Person@Example.test', Employee::find($employee->id)->email);
        $this->assertSame('+27 82 555 1212', Employee::find($employee->id)->phone);
        $this->assertSame('a very private reason', LeaveRequest::find($leave->id)->reason);
    }

    public function test_null_values_stay_null(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam, null, ['email' => null, 'phone' => null]);

        $raw = DB::table('employees')->where('id', $employee->id)->first();
        $this->assertNull($raw->email);
        $this->assertNull($raw->phone);
        $this->assertNull($raw->email_hash);
    }

    public function test_email_is_unique_per_team_case_insensitively_but_not_across_teams(): void
    {
        $a = $this->owner();
        $b = $this->owner();
        $this->employeeIn($a->currentTeam, null, ['email' => 'dup@example.test']);

        $this->actingAs($a)->post(route('employees.store'), [
            'first_name' => 'Dup', 'last_name' => 'Licate', 'email' => 'DUP@example.test',
            'employment_type' => 'full_time', 'status' => 'active',
        ])->assertSessionHasErrors('email');

        $this->actingAs($b)->post(route('employees.store'), [
            'first_name' => 'Fine', 'last_name' => 'Elsewhere', 'email' => 'dup@example.test',
            'employment_type' => 'full_time', 'status' => 'active',
        ])->assertSessionHasNoErrors();
    }

    public function test_updating_an_employee_to_a_taken_email_is_refused_but_keeping_your_own_is_fine(): void
    {
        $owner = $this->owner();
        $one = $this->employeeIn($owner->currentTeam, null, ['email' => 'one@example.test']);
        $two = $this->employeeIn($owner->currentTeam, null, ['email' => 'two@example.test']);
        $payload = fn ($email) => ['first_name' => 'X', 'last_name' => 'Y', 'email' => $email, 'employment_type' => 'full_time', 'status' => 'active'];

        $this->actingAs($owner)->put(route('employees.update', $two), $payload('one@example.test'))->assertSessionHasErrors('email');
        $this->actingAs($owner)->put(route('employees.update', $one), $payload('one@example.test'))->assertSessionHasNoErrors();
    }

    public function test_the_database_refuses_a_duplicate_email_in_one_team(): void
    {
        $owner = $this->owner();
        $this->employeeIn($owner->currentTeam, null, ['email' => 'dup@example.test']);

        $this->expectException(QueryException::class);
        $this->employeeIn($owner->currentTeam, null, ['email' => 'Dup@Example.test']);
    }

    public function test_several_employees_without_an_email_are_fine(): void
    {
        $owner = $this->owner();
        $this->employeeIn($owner->currentTeam, null, ['email' => null]);
        $this->employeeIn($owner->currentTeam, null, ['email' => null]);

        $this->assertSame(2, DB::table('employees')->count());
    }

    public function test_admin_can_search_by_exact_email_but_not_by_fragment(): void
    {
        $owner = $this->owner();
        $this->employeeIn($owner->currentTeam, null, ['first_name' => 'Findable', 'email' => 'find.me@example.test']);

        $this->actingAs($owner)->get(route('employees.index', ['search' => 'find.me@example.test']))->assertSee('Findable');
        $this->actingAs($owner)->get(route('employees.index', ['search' => 'find.me']))->assertDontSee('Findable');
        $this->actingAs($owner)->get(route('employees.index', ['search' => 'Findab']))->assertSee('Findable');
    }

    public function test_legacy_plaintext_rows_are_still_readable_before_the_data_migration_runs(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam);
        DB::table('employees')->where('id', $employee->id)->update(['phone' => '+27 plain legacy', 'email' => 'legacy@example.test']);

        $this->assertSame('+27 plain legacy', Employee::withoutTeamScope(fn () => Employee::find($employee->id))->phone);
    }

    public function test_the_data_protection_run_encrypts_legacy_rows_and_is_idempotent(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam, null, ['email' => null]);
        $leave = $this->leaveFor($employee, '2026-03-09', '2026-03-10');
        DB::table('employees')->where('id', $employee->id)->update(['phone' => '+27 plain legacy', 'email' => 'Legacy@Example.test', 'email_hash' => null]);
        DB::table('leave_requests')->where('id', $leave->id)->update(['reason' => 'plain legacy reason']);

        $first = (new SensitiveDataProtector)->run();
        $this->assertSame(['employees' => 1, 'leave_requests' => 1, 'duplicate_emails' => 0], $first);

        $raw = DB::table('employees')->where('id', $employee->id)->first();
        $this->assertStringNotContainsString('legacy', $raw->phone);
        $this->assertSame('+27 plain legacy', Crypt::decryptString($raw->phone));
        $this->assertSame('Legacy@Example.test', Crypt::decryptString($raw->email));
        $this->assertNotNull($raw->email_hash);
        $this->assertSame('plain legacy reason', Crypt::decryptString(DB::table('leave_requests')->where('id', $leave->id)->value('reason')));

        $snapshot = [DB::table('employees')->get()->toJson(), DB::table('leave_requests')->get()->toJson()];

        $second = (new SensitiveDataProtector)->run();
        $this->assertSame(['employees' => 0, 'leave_requests' => 0, 'duplicate_emails' => 0], $second);
        $this->assertSame($snapshot, [DB::table('employees')->get()->toJson(), DB::table('leave_requests')->get()->toJson()], 'second run changes nothing');
    }

    public function test_duplicate_legacy_emails_keep_their_data_but_only_one_is_indexed(): void
    {
        $owner = $this->owner();
        $one = $this->employeeIn($owner->currentTeam, null, ['email' => null]);
        $two = $this->employeeIn($owner->currentTeam, null, ['email' => null]);
        DB::table('employees')->where('id', $one->id)->update(['email' => 'same@example.test', 'email_hash' => null]);
        DB::table('employees')->where('id', $two->id)->update(['email' => 'SAME@example.test', 'email_hash' => null]);

        $result = (new SensitiveDataProtector)->run();

        $this->assertSame(1, $result['duplicate_emails']);
        $this->assertNotNull(DB::table('employees')->where('id', $one->id)->value('email_hash'));
        $this->assertNull(DB::table('employees')->where('id', $two->id)->value('email_hash'));
        $this->assertSame('SAME@example.test', Crypt::decryptString(DB::table('employees')->where('id', $two->id)->value('email')));
    }

    public function test_an_artisan_command_runs_the_same_protection(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam);
        DB::table('employees')->where('id', $employee->id)->update(['phone' => 'plain']);

        $this->artisan('hr:protect-data')->assertSuccessful();

        $this->assertSame('plain', Crypt::decryptString(DB::table('employees')->where('id', $employee->id)->value('phone')));
    }

    public function test_a_corrupt_ciphertext_is_an_error_not_silently_shown_as_text(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam);
        $parts = json_decode(base64_decode(Crypt::encryptString('secret')), true);
        $parts['mac'] = str_repeat('0', 64);
        $broken = base64_encode(json_encode($parts));
        DB::table('employees')->where('id', $employee->id)->update(['phone' => $broken]);

        $this->expectException(DecryptException::class);
        Employee::withoutTeamScope(fn () => Employee::find($employee->id))->phone;
    }
}
