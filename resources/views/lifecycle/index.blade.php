<x-app-layout>
<div class="page-pad">
    @php
        $onboarding = $open->where('process', 'onboarding');
        $offboarding = $open->where('process', 'offboarding');
        $stuck = $open->filter(fn ($c) => $c->due_on && $c->due_on->isPast() && $c->progress()['done'] < $c->progress()['total']);
    @endphp
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;">
        <div>
            <h1 class="rg-greet">
                @if ($open->isEmpty())
                    Nobody is joining or leaving right now.
                @else
                    <em>{{ $onboarding->count() }}</em> joining, <em>{{ $offboarding->count() }}</em> leaving{{ $stuck->isNotEmpty() ? ',' : '.' }}
                    @if ($stuck->isNotEmpty()) <em>{{ $stuck->count() }}</em> past {{ $stuck->count() === 1 ? 'its' : 'their' }} date. @endif
                @endif
            </h1>
            <p class="rg-sub" style="margin-bottom:0;">Start onboarding or offboarding from a person's employee record.</p>
        </div>
        <a href="{{ route('lifecycle.templates') }}" class="dot-btn">Edit the checklists</a>
    </div>

    <div style="margin-top:20px;"><x-flash /></div>

    @foreach (['onboarding' => $onboarding, 'offboarding' => $offboarding] as $process => $rows)
        @if ($rows->isNotEmpty())
            <section class="rg-section">
                <h2>{{ \App\Models\LifecycleChecklist::PROCESSES[$process] }}</h2>
                <div class="dot-card dot-card--pad">
                    @foreach ($rows as $checklist)
                        @php $p = $checklist->progress(); $hrLeft = $checklist->tasks->where('owner', 'hr')->whereNull('completed_at')->count(); $theirsLeft = $checklist->tasks->where('owner', 'employee')->whereNull('completed_at')->count(); @endphp
                        <a href="{{ route('lifecycle.show', $checklist) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                            <div style="min-width:0;">
                                <h4>{{ $checklist->employee->fullName() }}</h4>
                                <div class="m">
                                    {{ $hrLeft }} for HR · {{ $theirsLeft }} for them
                                    @if ($process === 'offboarding' && $checklist->last_day) · last day {{ $checklist->last_day->format('j M Y') }} @endif
                                    @if ($process === 'onboarding' && $checklist->due_on) · {{ $checklist->due_on->isPast() ? 'started' : 'starts' }} {{ $checklist->due_on->format('j M Y') }} @endif
                                </div>
                                <div class="rg-track" style="margin-top:6px;max-width:260px;"><i style="width: {{ $p['total'] ? round($p['done'] / $p['total'] * 100) : 0 }}%"></i></div>
                            </div>
                            <span class="dot-figure dot-note">{{ $p['done'] }}/{{ $p['total'] }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    @endforeach
</div>
</x-app-layout>
