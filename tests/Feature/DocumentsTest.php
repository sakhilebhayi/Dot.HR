<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\HrAuditLog;
use App\Models\HrDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * Employment documents and company policies (R-32 §10). HR administrators file and share; the
 * employee opens only what concerns them, accepts with their typed name, and hands documents in;
 * managers and other businesses see nothing; files are encrypted at rest and checked on the way out.
 */
class DocumentsTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    private const CONTRACT_TEXT = 'Basic salary R18 500 per month';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function pdf(string $name = 'contract.pdf', string $body = self::CONTRACT_TEXT): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n% {$body}\ntrailer<</Root 1 0 R>>\n%%EOF");
    }

    /** @return object{owner: User, manager: User, staff: User, other: User, employee: Employee, colleague: Employee} */
    private function team(): object
    {
        $owner = $this->owner();
        $manager = $this->memberOf($owner, 'editor');
        $staff = $this->memberOf($owner, 'viewer');
        $other = $this->memberOf($owner, 'viewer');
        $employee = $this->employeeIn($owner->currentTeam, $staff, ['first_name' => 'Thandi', 'last_name' => 'Mokoena']);
        $colleague = $this->employeeIn($owner->currentTeam, $other, ['first_name' => 'Sipho', 'last_name' => 'Dlamini']);

        return (object) compact('owner', 'manager', 'staff', 'other', 'employee', 'colleague');
    }

    private function file(object $t, array $extra = []): HrDocument
    {
        $this->actingAs($t->owner)->post(route('employees.documents.store', $t->employee), $extra + [
            'kind' => 'contract', 'title' => 'Employment contract 2026', 'file' => $this->pdf(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        return HrDocument::withoutTeamScope(fn () => HrDocument::query()->latest('id')->firstOrFail());
    }

    public function test_hr_files_a_document_encrypted_and_only_shares_it_on_purpose(): void
    {
        $t = $this->team();
        $doc = $this->file($t);

        $this->assertFalse($doc->shared);
        $stored = Storage::disk('local')->get($doc->path);
        $this->assertStringNotContainsString('R18 500', $stored, 'encrypted at rest');
        $this->assertTrue(HrAuditLog::withoutTeamScope(fn () => HrAuditLog::where('action', 'document.filed')->exists()));

        $this->actingAs($t->owner)->get(route('documents.download', $doc))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertSee('R18 500');
        $this->actingAs($t->staff)->get(route('documents.download', $doc))->assertNotFound();
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $t->staff->id]);

        $this->actingAs($t->owner)->post(route('documents.share', $doc))->assertRedirect();
        $this->actingAs($t->staff)->get(route('documents.download', $doc))->assertOk();
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $t->staff->id]);
    }

    public function test_managers_colleagues_and_other_businesses_never_reach_a_document(): void
    {
        $t = $this->team();
        $doc = $this->file($t, ['share' => 1]);

        $this->actingAs($t->manager)->get(route('documents.download', $doc))->assertNotFound();
        $this->actingAs($t->other)->get(route('documents.download', $doc))->assertNotFound();
        $this->actingAs($t->manager)->post(route('employees.documents.store', $t->employee), ['kind' => 'letter', 'title' => 'Manager letter', 'file' => $this->pdf()])->assertForbidden();
        $this->actingAs($t->manager)->get(route('employees.show', $t->employee))->assertDontSee('Employment contract 2026');

        $stranger = $this->owner();
        $this->actingAs($stranger)->get(route('documents.download', $doc))->assertNotFound();
        $this->actingAs($stranger)->post(route('documents.withdraw', $doc))->assertNotFound();
    }

    public function test_only_real_documents_get_in(): void
    {
        $t = $this->team();
        $this->actingAs($t->owner)->post(route('employees.documents.store', $t->employee), [
            'kind' => 'contract', 'title' => 'Not a PDF', 'file' => UploadedFile::fake()->createWithContent('contract.pdf', '<?php echo "hi";'),
        ])->assertSessionHasErrors('file');
        $this->actingAs($t->owner)->post(route('employees.documents.store', $t->employee), [
            'kind' => 'contract', 'title' => 'Far too big', 'file' => UploadedFile::fake()->create('big.pdf', 11 * 1024, 'application/pdf'),
        ])->assertSessionHasErrors('file');
        $this->assertSame(0, HrDocument::withoutTeamScope(fn () => HrDocument::count()));
    }

    public function test_acceptance_needs_the_persons_own_name_and_is_recorded(): void
    {
        $t = $this->team();
        $doc = $this->file($t, ['requires_acceptance' => 1]);
        $this->assertTrue($doc->shared);

        $this->actingAs($t->staff)->post(route('documents.acknowledge', $doc), ['confirm' => 1, 'typed_name' => 'Somebody Else'])->assertSessionHasErrors('typed_name');
        $this->actingAs($t->other)->post(route('documents.acknowledge', $doc), ['confirm' => 1, 'typed_name' => 'Sipho Dlamini'])->assertNotFound();
        $this->actingAs($t->staff)->post(route('documents.acknowledge', $doc), ['confirm' => 1, 'typed_name' => 'thandi mokoena'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('hr_document_acknowledgements', ['hr_document_id' => $doc->id, 'employee_id' => $t->employee->id, 'typed_name' => 'thandi mokoena']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $t->owner->id]);
        $this->actingAs($t->owner)->get(route('employees.show', $t->employee))->assertSee('accepted')->assertSee('thandi mokoena');

        // The person's own data export lists it and the acceptance (POPIA s23), without the file.
        $export = $this->actingAs($t->staff)->get(route('my-work.data'))->assertOk()->json();
        $this->assertSame('Employment contract 2026', $export['documents'][0]['title']);
        $this->assertSame('thandi mokoena', $export['acceptances_and_acknowledgements'][0]['typed_name']);
        $this->assertStringNotContainsString('R18 500', json_encode($export));

        // A document that does not ask for acceptance cannot be "accepted".
        $plain = $this->file($t, ['share' => 1, 'kind' => 'letter', 'title' => 'Confirmation letter']);
        $this->actingAs($t->staff)->post(route('documents.acknowledge', $plain), ['confirm' => 1, 'typed_name' => 'Thandi Mokoena'])->assertSessionHasErrors('typed_name');
    }

    public function test_an_employee_hands_a_document_in_for_hr_only(): void
    {
        $t = $this->team();
        $this->actingAs($t->staff)->post(route('my-work.documents.store'), ['kind' => 'qualification', 'title' => 'Matric certificate', 'file' => $this->pdf('matric.pdf', 'Matric')])->assertRedirect();
        $this->actingAs($t->staff)->post(route('my-work.documents.store'), ['kind' => 'contract', 'title' => 'My own contract', 'file' => $this->pdf()])->assertSessionHasErrors('kind');

        $doc = HrDocument::withoutTeamScope(fn () => HrDocument::query()->where('source', 'employee')->sole());
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $t->owner->id]);
        $this->actingAs($t->staff)->get(route('documents.download', $doc))->assertOk();
        $this->actingAs($t->owner)->get(route('documents.download', $doc))->assertOk();
        $this->actingAs($t->manager)->get(route('documents.download', $doc))->assertNotFound();
        $this->actingAs($t->staff)->get(route('my-work.index'))->assertSee('Matric certificate');
    }

    public function test_policies_reach_everyone_and_hr_sees_who_has_read_them(): void
    {
        $t = $this->team();
        $this->actingAs($t->staff)->post(route('policies.store'), ['title' => 'Staff policy', 'file' => $this->pdf()])->assertForbidden();
        $this->actingAs($t->owner)->post(route('policies.store'), ['title' => 'Leave policy', 'file' => $this->pdf('leave.pdf', 'Leave rules'), 'requires_acceptance' => 1])->assertRedirect();
        $policy = HrDocument::withoutTeamScope(fn () => HrDocument::query()->whereNull('employee_id')->sole());

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $t->staff->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $t->other->id]);
        $this->actingAs($t->staff)->get(route('policies.index'))->assertOk()->assertSee('Leave policy');
        $this->actingAs($t->staff)->get(route('documents.download', $policy))->assertOk();
        $this->actingAs($t->staff)->post(route('documents.acknowledge', $policy), ['confirm' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($t->owner)->get(route('policies.index'))->assertSee('1 of 2 confirmed');

        // A member with no linked record has no policies page; a withdrawn policy closes.
        $this->actingAs($this->memberOf($t->owner, 'viewer'))->get(route('policies.index'))->assertForbidden();
        $this->actingAs($t->owner)->post(route('documents.withdraw', $policy))->assertRedirect();
        $this->actingAs($t->staff)->get(route('documents.download', $policy))->assertNotFound();
        $this->actingAs($t->owner)->get(route('documents.download', $policy))->assertOk();
    }

    public function test_reads_are_audited_and_a_tampered_file_is_refused(): void
    {
        $t = $this->team();
        $doc = $this->file($t, ['share' => 1]);

        $this->actingAs($t->owner)->get(route('documents.download', $doc))->assertOk();
        $this->assertSame(1, HrAuditLog::withoutTeamScope(fn () => HrAuditLog::where('action', 'document.opened')->count()));
        $this->actingAs($t->staff)->get(route('documents.download', $doc))->assertOk();
        $this->assertSame(1, HrAuditLog::withoutTeamScope(fn () => HrAuditLog::where('action', 'document.opened')->count()), 'opening your own is not an access event');

        Storage::disk('local')->put($doc->path, Crypt::encryptString('swapped content'));
        $this->actingAs($t->owner)->get(route('documents.download', $doc))->assertStatus(503);
    }
}
