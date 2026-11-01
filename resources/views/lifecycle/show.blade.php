<x-app-layout>
<div class="page-pad" style="max-width:860px;">
    @php $p = $checklist->progress(); $open = $checklist->status === 'open'; $hasAccount = $checklist->employee->user_id !== null; @endphp
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('lifecycle.index') }}">Lifecycle</a> · <a href="{{ route('employees.show', $checklist->employee) }}">{{ $checklist->employee->fullName() }}</a></p>
    <h1 style="margin:0;">{{ $checklist->label() }} — {{ $checklist->employee->fullName() }}</h1>
    <p class="rg-sub" style="margin:4px 0 18px;">
        {{ $p['done'] }} of {{ $p['total'] }} steps done
        @if ($checklist->process === 'offboarding')
            · last day {{ $checklist->last_day?->format('j M Y') ?? 'not set' }} · {{ \App\Models\LifecycleChecklist::REASONS[$checklist->reason] ?? '—' }}
        @elseif ($checklist->due_on)
            · {{ $checklist->due_on->isPast() ? 'started' : 'starts' }} {{ $checklist->due_on->format('j M Y') }}
        @endif
        @unless ($open) · <span class="dot-badge dot-badge-muted">{{ ucfirst($checklist->status) }}</span> @endunless
    </p>

    <x-flash />

    @foreach (['hr' => 'For HR', 'employee' => 'For '.$checklist->employee->first_name] as $owner => $heading)
        @php $tasks = $checklist->tasks->where('owner', $owner); @endphp
        @if ($tasks->isNotEmpty())
            <section class="rg-section" style="margin-top:14px;">
                <h2>{{ $heading }}</h2>
                <div class="dot-card dot-card--pad">
                    @foreach ($tasks as $task)
                        @php $canTick = $open && ($owner === 'hr' || ! $hasAccount); @endphp
                        <div class="rg-task {{ $task->isDone() ? 'rg-task--done' : '' }}">
                            <form method="POST" action="{{ route('lifecycle.tasks.toggle', $task) }}">
                                @csrf
                                <button type="submit" class="rg-tick {{ $task->isDone() ? 'rg-tick--done' : '' }}" @disabled(! $canTick)
                                    aria-label="{{ $task->isDone() ? 'Mark not done' : 'Mark done' }}: {{ $task->title }}">✓</button>
                            </form>
                            <div style="min-width:0;flex:1;">
                                <div class="rg-task-title">{{ $task->title }} @if ($task->kind === 'acknowledge')<span class="dot-badge dot-badge-accent" style="margin-left:4px;">to acknowledge</span>@endif</div>
                                @if ($task->isDone())
                                    <div class="rg-owner">{{ $task->kind === 'acknowledge' ? 'Acknowledged' : 'Done' }} by {{ $task->completer?->name ?? 'someone' }} · {{ $task->completed_at->format('j M Y, H:i') }}</div>
                                @elseif ($owner === 'employee' && $hasAccount)
                                    <div class="rg-owner">They tick this themselves in Dot.HR.</div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    @endforeach

    @if ($open)
        <div style="display:flex;gap:8px;margin-top:22px;flex-wrap:wrap;align-items:center;">
            <form method="POST" action="{{ route('lifecycle.finish', $checklist) }}" style="margin:0;"
                @if ($checklist->process === 'offboarding') data-confirm="Record {{ $checklist->employee->fullName() }} as having left on {{ $checklist->last_day?->format('j M Y') }}?" @endif>
                @csrf
                <button type="submit" class="dot-btn dot-btn-primary">{{ $checklist->process === 'offboarding' ? 'Finish — record the departure' : 'Finish onboarding' }}</button>
            </form>
            <form method="POST" action="{{ route('lifecycle.cancel', $checklist) }}" style="margin:0;">
                @csrf
                <button type="submit" class="dot-btn dot-btn-danger">Cancel</button>
            </form>
            @if ($p['done'] < $p['total'])
                <span class="dot-note">{{ $p['total'] - $p['done'] }} {{ Str::plural('step', $p['total'] - $p['done']) }} still open — finishing keeps them recorded as not done.</span>
            @endif
        </div>
    @endif
</div>
</x-app-layout>
