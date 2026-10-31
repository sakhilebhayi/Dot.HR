<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\LifecycleChecklist;
use App\Models\LifecycleTask;
use App\Services\Hr\Lifecycle;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * HR's side of onboarding and offboarding (R-32 Phase 5): who has outstanding steps, starting
 * and finishing a checklist, ticking HR's own tasks, and the business's default checklists.
 * HR administrators only; every model here is team-scoped, so another business's checklist
 * is a 404.
 */
class LifecycleController extends Controller
{
    public function __construct(private Lifecycle $lifecycle) {}

    public function index(Request $request): View
    {
        $this->admin($request);

        $open = LifecycleChecklist::query()
            ->where('status', 'open')
            ->with(['employee', 'tasks'])
            ->orderBy('due_on')
            ->get();

        return view('lifecycle.index', ['open' => $open]);
    }

    public function start(Request $request, Employee $employee, string $process): RedirectResponse
    {
        $this->admin($request);
        abort_unless(array_key_exists($process, LifecycleChecklist::PROCESSES), 404);

        $data = $request->validate([
            'due_on' => ['nullable', 'date'],
            'last_day' => [$process === 'offboarding' ? 'required' : 'nullable', 'date'],
            'reason' => [$process === 'offboarding' ? 'required' : 'nullable', Rule::in(array_keys(LifecycleChecklist::REASONS))],
        ]);

        abort_if($employee->status === 'terminated', 422, 'This person has already left.');

        $checklist = $this->lifecycle->start($employee, $process, $request->user()->id, $data);

        return redirect()->route('lifecycle.show', $checklist)->with('status', LifecycleChecklist::PROCESSES[$process].' started for '.$employee->fullName().'.');
    }

    public function show(Request $request, LifecycleChecklist $checklist): View
    {
        $this->admin($request);

        return view('lifecycle.show', ['checklist' => $checklist->load(['employee', 'tasks.completer'])]);
    }

    /**
     * Tick or untick a task. HR ticks HR tasks; a person's own tasks are theirs to tick —
     * except for someone with no Dot.HR account, whom HR may complete on behalf of.
     */
    public function toggle(Request $request, LifecycleTask $task): RedirectResponse
    {
        $this->admin($request);

        $checklist = $task->checklist;
        abort_if($task->owner === 'employee' && $checklist->employee->user_id !== null, 403, 'This step belongs to the person; they tick it themselves.');

        $this->lifecycle->setDone($task, ! $task->isDone(), $request->user()->id);

        return back();
    }

    public function finish(Request $request, LifecycleChecklist $checklist): RedirectResponse
    {
        $this->admin($request);
        $this->lifecycle->finish($checklist, $request->user()->id);

        $message = $checklist->process === 'offboarding'
            ? $checklist->employee->fullName().' is recorded as having left on '.($checklist->last_day?->format('j M Y') ?? 'today').'.'
            : 'Onboarding complete.';

        return redirect()->route('lifecycle.index')->with('status', $message);
    }

    public function cancel(Request $request, LifecycleChecklist $checklist): RedirectResponse
    {
        $this->admin($request);
        $this->lifecycle->cancel($checklist);

        return redirect()->route('lifecycle.index')->with('status', $checklist->label().' cancelled. Nothing on the employee record changed.');
    }

    public function editTemplates(Request $request): View
    {
        $access = $this->admin($request);

        return view('lifecycle.templates', [
            'items' => [
                'onboarding' => ChecklistTemplate::itemsFor($access->team()->id, 'onboarding'),
                'offboarding' => ChecklistTemplate::itemsFor($access->team()->id, 'offboarding'),
            ],
        ]);
    }

    /**
     * Save a business's own checklist for one process from three plain lists: what the person
     * does, what HR does, and policies the person acknowledges ("Title — text" per line).
     */
    public function updateTemplate(Request $request, string $process): RedirectResponse
    {
        $access = $this->admin($request);
        abort_unless(array_key_exists($process, LifecycleChecklist::PROCESSES), 404);

        $data = $request->validate([
            'employee_tasks' => ['nullable', 'string', 'max:4000'],
            'hr_tasks' => ['nullable', 'string', 'max:4000'],
            'acknowledgements' => ['nullable', 'string', 'max:8000'],
        ]);

        $lines = fn (?string $text) => collect(preg_split('/\R/', (string) $text) ?: [])->map(fn ($l) => trim($l))->filter()->take(25);

        $items = [
            ...$lines($data['employee_tasks'] ?? null)->map(fn ($t) => ['title' => mb_substr($t, 0, 160), 'owner' => 'employee', 'kind' => 'task'])->all(),
            ...$lines($data['acknowledgements'] ?? null)->map(function ($line) {
                [$title, $body] = array_pad(preg_split('/\s+—\s+|\s+-\s+/', $line, 2) ?: [], 2, null);

                return ['title' => mb_substr((string) $title, 0, 160), 'owner' => 'employee', 'kind' => 'acknowledge', 'body' => $body];
            })->all(),
            ...$lines($data['hr_tasks'] ?? null)->map(fn ($t) => ['title' => mb_substr($t, 0, 160), 'owner' => 'hr', 'kind' => 'task'])->all(),
        ];

        if ($items === []) {
            return back()->with('error', 'A checklist needs at least one step. Clear a business checklist by restoring the defaults instead.');
        }

        $template = ChecklistTemplate::query()->firstOrNew(['process' => $process]);
        $template->forceFill(['team_id' => $access->team()->id, 'items' => $items])->save();

        return back()->with('status', LifecycleChecklist::PROCESSES[$process].' checklist saved. It applies to checklists started from now on.');
    }

    public function resetTemplate(Request $request, string $process): RedirectResponse
    {
        $this->admin($request);
        ChecklistTemplate::query()->where('process', $process)->delete();

        return back()->with('status', 'Back to the Dot.HR defaults for '.strtolower(LifecycleChecklist::PROCESSES[$process] ?? $process).'.');
    }

    private function admin(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->isAdmin(), 403);

        return $access;
    }
}
