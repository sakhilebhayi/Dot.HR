<?php

namespace Tests\Feature;

use App\Models\ContractorInvoice;
use App\Models\Employee;
use App\Models\Engagement;
use App\Models\HrAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * Contractor self-service (brief §12): invoices sent from My work with the file (encrypted,
 * checked), and the contractor's own business details, with no bank details anywhere.
 */
class ContractorSelfServiceTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @return object{owner: User, contractor: User, staff: User, person: Employee, engagement: Engagement} */
    private function team(): object
    {
        $owner = $this->owner();
        $contractor = $this->memberOf($owner, 'viewer');
        $staff = $this->memberOf($owner, 'viewer');
        $person = $this->employeeIn($owner->currentTeam, $contractor, ['first_name' => 'Lerato', 'last_name' => 'Nkosi', 'employment_type' => 'contractor']);
        $this->employeeIn($owner->currentTeam, $staff);

        $this->actingAs($owner)->post(route('contractors.store'), [
            'employee_id' => $person->id, 'kind' => 'freelancer', 'title' => 'Shop fit-out design', 'rate_basis' => 'fixed',
            'rate_amount' => '25000', 'currency' => 'ZAR', 'starts_on' => now()->subMonth()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $engagement = Engagement::withoutTeamScope(fn () => Engagement::query()->latest('id')->firstOrFail());
        $this->actingAs($owner)->post(route('contractors.milestones.store', $engagement), ['title' => 'Concept drawings', 'amount' => '10000'])->assertRedirect();

        return (object) compact('owner', 'contractor', 'staff', 'person', 'engagement');
    }

    private function pdf(string $text = 'INVOICE LN-014 R10 000'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('LN-014.pdf', "%PDF-1.4\n% {$text}\n%%EOF");
    }

    public function test_a_contractor_sends_an_invoice_with_the_file_and_hr_reviews_it(): void
    {
        $t = $this->team();
        $milestone = $t->engagement->milestones()->first();

        $this->actingAs($t->contractor)->post(route('my-work.invoices.store', $t->engagement), [
            'reference' => 'LN-014', 'amount' => '10000', 'issued_on' => now()->toDateString(), 'engagement_milestone_id' => $milestone->id, 'file' => $this->pdf(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $invoice = ContractorInvoice::withoutTeamScope(fn () => ContractorInvoice::query()->sole());
        $this->assertSame(['received', 'contractor', $milestone->id], [$invoice->status, $invoice->source, $invoice->engagement_milestone_id]);
        $this->assertStringNotContainsString('LN-014 R10', Storage::disk('local')->get($invoice->file_path), 'encrypted at rest');
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $t->owner->id]);

        // HR sees it on the engagement, with the file, and can act on it as on any invoice.
        $this->actingAs($t->owner)->get(route('contractors.show', $t->engagement))->assertSee('sent by the contractor')->assertSee('Concept drawings (not approved yet)');
        $this->actingAs($t->owner)->get(route('invoices.file', $invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertSee('LN-014 R10 000');
        $this->assertTrue(HrAuditLog::withoutTeamScope(fn () => HrAuditLog::query()->where('action', 'invoice.file_opened')->exists()));
        $this->actingAs($t->owner)->post(route('invoices.move', $invoice), ['status' => 'approved'])->assertRedirect();
        $this->actingAs($t->contractor)->get(route('my-work.index'))->assertSee('Approved for payment')->assertSee('Open the invoice');

        // The contractor opens their own file; nobody else in the business does.
        $this->actingAs($t->contractor)->get(route('my-work.invoices.file', $invoice))->assertOk();
        $this->actingAs($t->staff)->get(route('my-work.invoices.file', $invoice))->assertNotFound();
        $this->actingAs($t->staff)->get(route('invoices.file', $invoice))->assertForbidden();
    }

    public function test_only_sound_invoices_get_in(): void
    {
        $t = $this->team();
        $send = fn (array $over) => $this->actingAs($t->contractor)->post(route('my-work.invoices.store', $t->engagement), $over + [
            'reference' => 'LN-015', 'amount' => '500', 'issued_on' => now()->toDateString(), 'file' => $this->pdf(),
        ]);

        $send(['file' => UploadedFile::fake()->createWithContent('x.pdf', '<?php echo 1;')])->assertSessionHasErrors('file');
        $send(['issued_on' => now()->addDay()->toDateString()])->assertSessionHasErrors('issued_on');
        $send([])->assertSessionHasNoErrors();
        $send([])->assertSessionHasErrors('reference');

        // Not their engagement: 404. An ended one: refused with a reason.
        $this->actingAs($t->staff)->post(route('my-work.invoices.store', $t->engagement), ['reference' => 'X-1', 'amount' => '1', 'issued_on' => now()->toDateString(), 'file' => $this->pdf()])->assertNotFound();
        $t->engagement->forceFill(['status' => 'ended'])->save();
        $send(['reference' => 'LN-016'])->assertSessionHasErrors('reference');
        $this->assertSame(1, ContractorInvoice::withoutTeamScope(fn () => ContractorInvoice::query()->count()));
    }

    public function test_business_details_are_theirs_to_keep_and_never_bank_details(): void
    {
        $t = $this->team();
        $this->actingAs($t->contractor)->put(route('my-work.contractor-details'), [
            'trading_name' => 'Nkosi Design Studio', 'registration_number' => '2019/123456/07', 'vat_number' => '4123456789',
            'billing_address' => '4 Church Street, Mahikeng', 'invoice_email' => 'accounts@nkosi.example', 'bank_account' => '1234567890',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('contractor_profiles', ['trading_name' => null]);
        $this->assertStringNotContainsString('1234567890', json_encode(\DB::table('contractor_profiles')->first()));

        $this->actingAs($t->owner)->get(route('contractors.show', $t->engagement))->assertOk()
            ->assertSee('Nkosi Design Studio')->assertSee('2019/123456/07')->assertSee('No bank details are kept');
        $this->actingAs($t->staff)->put(route('my-work.contractor-details'), ['trading_name' => 'Not a contractor'])->assertNotFound();
    }
}
