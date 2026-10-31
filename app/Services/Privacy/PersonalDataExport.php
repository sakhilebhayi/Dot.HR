<?php

namespace App\Services\Privacy;

use App\Models\Application;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\Engagement;
use App\Models\Goal;
use App\Models\HrRequest;
use App\Models\InternalInvitation;
use App\Models\LeaveRequest;
use App\Models\LifecycleChecklist;
use App\Models\ProfessionalProfile;
use App\Models\Review;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\TrainingRecord;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Everything Dot.HR holds about a person, as one readable file (R-32 Phase 8, POPIA s23
 * "access to personal information"). Two views:
 *
 *   forEmployee  what one business holds about someone who works there. The person gets it from
 *                My work; an HR administrator can produce the same file to answer a formal
 *                request. Unshared review feedback and employer-only hiring notes go only into
 *                the HR copy — the business decides what to release from it.
 *   forUser      what the person keeps in their own career space: profile, skills, history,
 *                and the applications they made to any business, as they see them.
 *
 * Encrypted fields are decrypted for the export: it is the person's own data. Nothing about
 * other people is included beyond the names of those who acted (a reviewer, an approver).
 */
final class PersonalDataExport
{
    /** @return array<string, mixed> */
    public function forEmployee(Employee $employee, bool $forHr): array
    {
        $id = $employee->id;
        $employee->loadMissing('position', 'team');

        return [
            'about_this_file' => [
                'business' => $employee->team->name,
                'produced_at' => now()->toIso8601String(),
                'produced_for' => $forHr ? 'an HR administrator answering an access request' : 'the person themselves',
                'note' => 'Everything Dot.HR holds about this person in this business. Dates are ISO 8601.',
            ],
            'employee_record' => [
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'emergency_contact_name' => $employee->emergency_contact_name,
                'emergency_contact_phone' => $employee->emergency_contact_phone,
                'position' => $employee->position?->title,
                'employment_type' => $employee->employment_type,
                'status' => $employee->status,
                'start_date' => $this->d($employee->start_date),
                'end_date' => $this->d($employee->end_date),
            ],
            'leave' => LeaveRequest::query()->where('employee_id', $id)->with('leaveType')->orderBy('start_date')->get()->map(fn (LeaveRequest $l) => [
                'type' => $l->typeLabel(), 'from' => $this->d($l->start_date), 'to' => $this->d($l->end_date),
                'half_day' => $l->half_day, 'reason' => $l->reason, 'status' => $l->status, 'decided_at' => $this->d($l->reviewed_at),
            ])->all(),
            'onboarding_and_offboarding' => LifecycleChecklist::query()->where('employee_id', $id)->with('tasks')->get()->map(fn (LifecycleChecklist $c) => [
                'process' => $c->process, 'status' => $c->status, 'last_day' => $this->d($c->last_day), 'reason' => $c->reason,
                'steps' => $c->tasks->map(fn ($t) => ['title' => $t->title, 'for' => $t->owner, 'done_at' => $this->d($t->completed_at)])->all(),
            ])->all(),
            'hr_requests' => HrRequest::query()->where('employee_id', $id)->oldest()->get()->map(fn (HrRequest $r) => [
                'reference' => $r->reference(), 'category' => $r->category, 'subject' => $r->subject, 'question' => $r->body,
                'answer' => $r->response, 'status' => $r->status, 'raised_at' => $this->d($r->created_at), 'answered_at' => $this->d($r->responded_at),
            ])->all(),
            'shifts' => Shift::query()->whereHas('employees', fn ($q) => $q->whereKey($id))->orderBy('starts_at')->get()->map(fn (Shift $s) => [
                'from' => $this->d($s->starts_at), 'to' => $this->d($s->ends_at), 'break_minutes' => $s->break_minutes, 'title' => $s->title, 'location' => $s->location,
            ])->all(),
            'attendance' => AttendanceRecord::query()->where('employee_id', $id)->orderBy('clock_in_at')->get()->map(fn (AttendanceRecord $a) => [
                'in' => $this->d($a->clock_in_at), 'out' => $this->d($a->clock_out_at), 'break_minutes' => $a->break_minutes,
                'recorded_by' => $a->source === 'self' ? 'themselves' : 'a supervisor', 'note' => $a->note,
            ])->all(),
            'timesheets' => Timesheet::query()->where('employee_id', $id)->orderBy('week_start')->get()->map(fn (Timesheet $t) => [
                'week_of' => $this->d($t->week_start), 'status' => $t->status, 'worked_minutes' => $t->worked_minutes,
                'overtime_minutes' => $t->overtime_minutes, 'decision_note' => $t->decision_note,
            ])->all(),
            'certifications' => EmployeeCertification::query()->where('employee_id', $id)->get()->map(fn (EmployeeCertification $c) => [
                'name' => $c->name, 'issuer' => $c->issuer, 'issued_on' => $this->d($c->issued_on), 'expires_on' => $this->d($c->expires_on), 'verified_at' => $this->d($c->verified_at),
            ])->all(),
            'training' => TrainingRecord::query()->where('employee_id', $id)->oldest()->get()->map(fn (TrainingRecord $t) => [
                'title' => $t->title, 'provider' => $t->provider, 'status' => $t->status, 'starts_on' => $this->d($t->starts_on),
                'completed_on' => $this->d($t->completed_on), 'hours' => $t->hours, 'reason' => $t->reason, 'decision_note' => $t->decision_note,
            ])->all(),
            'goals' => Goal::query()->where('employee_id', $id)->oldest()->get()->map(fn (Goal $g) => [
                'title' => $g->title, 'detail' => $g->detail, 'for' => $g->kind, 'status' => $g->status, 'due_on' => $this->d($g->due_on),
            ])->all(),
            'reviews' => Review::query()->where('employee_id', $id)->with(['cycle', 'reviewer'])->oldest()->get()->map(fn (Review $r) => [
                'cycle' => $r->cycle->name, 'reviewer' => $r->reviewer->name, 'status' => $r->status,
                'own_reflection' => $r->self_assessment,
                'feedback' => ($forHr || in_array($r->status, ['shared', 'acknowledged'], true)) ? $r->feedback : null,
                'response' => $r->response, 'shared_at' => $this->d($r->shared_at),
            ])->all(),
            'contractor_engagements' => Engagement::query()->where('employee_id', $id)->with(['milestones', 'invoices'])->get()->map(fn (Engagement $e) => [
                'title' => $e->title, 'kind' => $e->kind, 'scope' => $e->scope, 'rate' => $e->rate(), 'from' => $this->d($e->starts_on),
                'to' => $this->d($e->ended_on ?? $e->ends_on), 'status' => $e->status,
                'milestones' => $e->milestones->map(fn ($m) => ['title' => $m->title, 'status' => $m->status, 'note' => $m->decision_note])->all(),
                'invoices' => $e->invoices->map(fn ($i) => ['reference' => $i->reference, 'amount' => $i->amount, 'status' => $i->status, 'note' => $i->note])->all(),
            ])->all(),
            'roles_you_were_told_about' => InternalInvitation::query()->where('employee_id', $id)->with('vacancy')->get()->map(fn (InternalInvitation $i) => [
                'role' => $i->vacancy->title, 'on' => $this->d($i->created_at),
            ])->all(),
            'applications_to_this_business' => $employee->user_id ? Application::query()->where('user_id', $employee->user_id)
                ->with(['vacancy', 'notes'])->get()->map(fn (Application $a) => array_filter([
                    'role' => $a->vacancy->title, 'stage' => $forHr ? $a->stage : $a->candidateStageLabel(), 'applied_at' => $this->d($a->created_at),
                    'cover_note' => $a->cover_note, 'answers' => $a->answers,
                    'employer_notes' => $forHr ? $a->notes->map(fn ($n) => ['note' => $n->body, 'at' => $this->d($n->created_at)])->all() : null,
                ], fn ($v) => $v !== null))->values()->all() : [],
        ];
    }

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        $profile = ProfessionalProfile::query()->where('user_id', $user->id)->with(['skills', 'entries'])->first();

        return [
            'about_this_file' => [
                'produced_at' => now()->toIso8601String(),
                'note' => 'Your account, your professional profile and the applications you made on Dot.HR. What each business holds about you as an employee is in that business\'s My work export.',
            ],
            'account' => ['name' => $user->name, 'email' => $user->email, 'created_at' => $this->d($user->created_at)],
            'profile' => $profile ? [
                'headline' => $profile->headline, 'about' => $profile->about, 'location' => $profile->location,
                'availability' => $profile->availability, 'visibility' => $profile->visibility,
                'work_types' => $profile->work_types, 'work_arrangements' => $profile->work_arrangements,
                'skills' => $profile->skills->pluck('name')->all(),
                'history' => $profile->entries->map(fn ($e) => [
                    'kind' => $e->kind, 'title' => $e->title, 'organisation' => $e->organisation,
                    'from' => $this->d($e->started_on), 'to' => $e->is_current ? 'current' : $this->d($e->ended_on), 'description' => $e->description,
                ])->all(),
            ] : null,
            'applications' => Application::withoutTeamScope(fn () => Application::query()->where('user_id', $user->id)
                ->with(['vacancy.team', 'interviews', 'offers'])->oldest()->get()->map(fn (Application $a) => [
                    'business' => $a->vacancy->team->name, 'role' => $a->vacancy->title, 'stage' => $a->candidateStageLabel(),
                    'applied_at' => $this->d($a->created_at), 'cover_note' => $a->cover_note, 'answers' => $a->answers,
                    'interviews' => $a->interviews->map(fn ($i) => ['at' => $this->d($i->starts_at), 'mode' => $i->mode, 'where' => $i->where])->all(),
                    'offers' => $a->offers->where('status', '!=', 'draft')->map(fn ($o) => [
                        'job_title' => $o->job_title, 'pay' => $o->pay_amount, 'currency' => $o->currency, 'starts_on' => $this->d($o->starts_on), 'status' => $o->status,
                    ])->values()->all(),
                ])->all()),
        ];
    }

    private function d(?CarbonInterface $date): ?string
    {
        return $date?->toIso8601String();
    }
}
