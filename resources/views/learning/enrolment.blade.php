<x-app-layout>
<div class="page-pad" style="max-width:900px;">
    @php
        $path = $enrolment->path;
        $who = $enrolment->employee;
        $done = collect($rows)->where('state', 'done')->count();
        $total = count($rows);
        $me = auth()->id();
    @endphp
    <p class="dot-note" style="margin:0 0 6px;">
        @if ($asHr)<a href="{{ route('learning.paths.show', $path) }}">{{ $path->name }}</a>@else<a href="{{ route('my-work.learning.index') }}">Your learning</a>@endif
    </p>
    <h1 class="rg-greet">
        {{ $asHr ? $who->first_name.' on '.$path->name : $path->name }}{{ $enrolment->completed_at ? ',' : ':' }}
        @if ($enrolment->completed_at)
            <em>completed {{ $enrolment->completed_at->format('j M Y') }}</em>.
        @elseif ($enrolment->dropped_at)
            stopped {{ $enrolment->dropped_at->format('j M Y') }}.
        @else
            <em>{{ $done }} of {{ $total }}</em> {{ Str::plural('step', $total) }} done.
        @endif
    </h1>
    <p class="rg-sub">
        @if ($path->description){{ $path->description }} @endif
        Started {{ $enrolment->started_on->format('j M Y') }}.
        {{ $asHr ? 'Only '.$who->first_name.' and HR see this.' : 'Only you and HR see this.' }}
    </p>
    @if ($total > 0 && ! $enrolment->dropped_at)
        <div class="rg-track" style="max-width:420px;margin:-6px 0 18px;" role="img" aria-label="{{ $done }} of {{ $total }} steps done"><i style="width:{{ round($done / $total * 100) }}%;"></i></div>
    @endif

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    <div class="dot-card dot-card--pad">
        @if ($rows === [])
            <p class="dot-note" style="margin:0;">This path has no steps yet.</p>
        @else
            <ol class="rg-path">
                @foreach ($rows as $row)
                    @php $step = $row['step']; $p = $row['progress']; $open = ! $enrolment->dropped_at && ! $enrolment->completed_at; @endphp
                    <li class="{{ $row['state'] === 'done' ? 'is-done' : '' }}">
                        <div style="min-width:0;">
                            <h4>{{ $step->title }}</h4>
                            <div class="m">
                                @include('learning._step-meta', ['dated' => true])
                                @if ($row['due'] && $row['state'] !== 'done') Due by {{ $row['due']->format('j M Y') }}. @endif
                            </div>
                        </div>
                        <div class="act">
                            @switch ($row['state'])
                                @case ('done')
                                    <span class="dot-badge dot-badge-ok">Done {{ $p->done_on->format('j M') }}</span>
                                    @if (! $step->isCourse() && ($asHr || $p->confirmed_by === $me) && ! $enrolment->dropped_at)
                                        <form method="POST" action="{{ $asHr ? route('learning.steps.undo', [$enrolment, $step]) : route('my-work.learning.undo', [$enrolment, $step]) }}">@csrf<button type="submit" class="dot-btn dot-btn-ghost" style="min-height:28px;padding:2px 8px;" aria-label="Undo: {{ $step->title }}">Undo</button></form>
                                    @endif
                                    @break
                                @case ('booked')
                                    <span class="dot-badge dot-badge-accent">Booked{{ $p->training->starts_on ? ' for '.$p->training->starts_on->format('j M') : '' }}</span>
                                    @if ($asHr)<a href="{{ route('training.index') }}" class="dot-btn dot-btn-ghost" style="min-height:28px;padding:2px 8px;">Record completion in Training</a>@endif
                                    @break
                                @case ('asked')
                                    <span class="dot-badge dot-badge-muted">Asked for</span>
                                    @if ($asHr)<a href="{{ route('training.index') }}" class="dot-btn dot-btn-ghost" style="min-height:28px;padding:2px 8px;">Decide in Training</a>@endif
                                    @break
                                @default
                                    @if ($row['state'] === 'late')<span class="dot-badge dot-badge-warn">Past its date</span>@endif
                                    @if ($open && $step->isCourse())
                                        @if ($asHr)
                                            <form method="POST" action="{{ route('learning.steps.book', [$enrolment, $step]) }}">
                                                @csrf
                                                <label for="bk-{{ $step->id }}" style="position:absolute;left:-9999px;">Date for {{ $step->title }}</label>
                                                <input id="bk-{{ $step->id }}" name="starts_on" type="date" class="dot-input" style="width:auto;min-height:32px;padding:3px 8px;">
                                                <button type="submit" class="dot-btn dot-btn-primary" style="min-height:32px;padding:3px 12px;">Book it</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('my-work.learning.ask', [$enrolment, $step]) }}">@csrf<button type="submit" class="dot-btn" style="min-height:32px;padding:3px 12px;">Ask for this course</button></form>
                                        @endif
                                    @elseif ($open)
                                        @if ($asHr)
                                            <form method="POST" action="{{ route('learning.steps.done', [$enrolment, $step]) }}">
                                                @csrf
                                                <label for="dn-{{ $step->id }}" style="position:absolute;left:-9999px;">Done on, for {{ $step->title }}</label>
                                                <input id="dn-{{ $step->id }}" name="done_on" type="date" class="dot-input" style="width:auto;min-height:32px;padding:3px 8px;" value="{{ \App\Support\Hr\LocalTime::today()->toDateString() }}">
                                                <button type="submit" class="dot-btn" style="min-height:32px;padding:3px 12px;">Mark done</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('my-work.learning.done', [$enrolment, $step]) }}">@csrf<button type="submit" class="dot-btn" style="min-height:32px;padding:3px 12px;" aria-label="Mark done: {{ $step->title }}">I’ve done this</button></form>
                                        @endif
                                    @endif
                            @endswitch
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>

    @if ($asHr && $enrolment->isActive())
        <form method="POST" action="{{ route('learning.enrolments.drop', $enrolment) }}" style="margin:14px 0 0;" data-confirm="Take {{ $who->first_name }} off {{ $path->name }}? What was done stays on record, and they can be put on it again.">
            @csrf
            <button type="submit" class="dot-btn dot-btn-ghost">Take {{ $who->first_name }} off this path</button>
        </form>
    @endif
</div>
</x-app-layout>
