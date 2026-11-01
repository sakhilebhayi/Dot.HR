<x-app-layout>
<div class="page-pad" style="max-width:960px;">
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('learning.index') }}">Learning paths</a></p>
    <h1 class="rg-greet">{{ $path->name }}{{ $active->isNotEmpty() ? ',' : '.' }} @if ($active->isNotEmpty())<em>{{ $active->count() }} {{ Str::plural('person', $active->count()) }}</em> on it.@endif</h1>
    <p class="rg-sub">
        @if ($path->description){{ $path->description }} @endif
        {{ $path->position ? 'For the '.$path->position->title.' position.' : 'For any position.' }}
        {{ $path->self_enrol ? 'Anyone here may start it themselves.' : 'People start it when HR puts them on it.' }}
    </p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    <section class="rg-section" id="steps" style="margin-top:8px;">
        <h2>The steps, in order</h2>
        <div class="dot-card dot-card--pad">
            @if ($steps->isEmpty())
                <p class="dot-note" style="margin:0;">No steps yet. {{ $isAdmin ? 'Add the first one below.' : 'HR adds them.' }}</p>
            @else
                <ol class="rg-path">
                    @foreach ($steps as $step)
                        <li>
                            <div style="min-width:0;"><h4>{{ $step->title }}</h4><div class="m">@include('learning._step-meta')</div></div>
                            @if ($isAdmin)
                                <div class="act">
                                    <a href="{{ route('learning.paths.show', [$path, 'step' => $step->id]) }}#step-form" class="dot-btn dot-btn-ghost" style="min-height:28px;padding:2px 8px;">Edit</a>
                                    @unless ($loop->first)<form method="POST" action="{{ route('learning.steps.move', [$step, 'up']) }}">@csrf<button type="submit" class="dot-btn dot-btn-ghost" style="min-height:28px;padding:2px 8px;" aria-label="Move {{ $step->title }} up">Up</button></form>@endunless
                                    @unless ($loop->last)<form method="POST" action="{{ route('learning.steps.move', [$step, 'down']) }}">@csrf<button type="submit" class="dot-btn dot-btn-ghost" style="min-height:28px;padding:2px 8px;" aria-label="Move {{ $step->title }} down">Down</button></form>@endunless
                                    <form method="POST" action="{{ route('learning.steps.retire', $step) }}" data-confirm="Retire {{ $step->title }}? It leaves the path for everyone; anyone who did it keeps it on record.">@csrf<button type="submit" class="dot-btn dot-btn-ghost" style="min-height:28px;padding:2px 8px;">Retire</button></form>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>

        @if ($isAdmin)
            @php $s = $editingStep; @endphp
            <form method="POST" action="{{ $s ? route('learning.steps.update', $s) : route('learning.steps.store', $path) }}" class="dot-card dot-card--pad" style="display:grid;gap:12px;margin-top:14px;" id="step-form">
                @csrf
                @if ($s) @method('PUT') @endif
                <div class="dot-label" style="margin:0;">{{ $s ? 'Edit '.$s->title : 'Add a step' }}</div>
                <div class="rg-form-row">
                    <div><label for="ls-title">What</label><input id="ls-title" name="title" class="dot-input" maxlength="160" required value="{{ old('title', $s?->title) }}" placeholder="Cash handling course"></div>
                    <div>
                        <label for="ls-kind">Kind</label>
                        <select id="ls-kind" name="kind" class="dot-input">
                            @foreach (\App\Models\LearningPathStep::KINDS as $value => $label)<option value="{{ $value }}" @selected(old('kind', $s?->kind ?? 'course') === $value)>{{ $label }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div><label for="ls-detail">What to do, in a sentence (optional)</label><input id="ls-detail" name="detail" class="dot-input" maxlength="500" value="{{ old('detail', $s?->detail) }}" placeholder="Shadow a supervisor through two full cash-ups."></div>
                <div class="rg-form-row">
                    <div><label for="ls-provider">Run by, for a course (optional)</label><input id="ls-provider" name="provider" class="dot-input" maxlength="140" value="{{ old('provider', $s?->provider) }}"></div>
                    <div><label for="ls-cert">Certificate it gives, for a course (optional)</label><input id="ls-cert" name="certification_name" class="dot-input" maxlength="140" value="{{ old('certification_name', $s?->certification_name) }}" placeholder="Match the position requirement’s name"></div>
                </div>
                <div class="rg-form-row">
                    <div>
                        <label for="ls-comp">Competency it grows (optional)</label>
                        <select id="ls-comp" name="competency_id" class="dot-input">
                            <option value="">None</option>
                            @foreach ($competencies as $c)<option value="{{ $c->id }}" @selected((int) old('competency_id', $s?->competency_id) === $c->id)>{{ $c->name }}</option>@endforeach
                        </select>
                    </div>
                    <div><label for="ls-days">Within how many days of starting (optional)</label><input id="ls-days" name="within_days" type="number" min="1" max="730" class="dot-input" value="{{ old('within_days', $s?->within_days) }}" placeholder="14"></div>
                </div>
                <p class="dot-note" style="margin:0;">A course is booked as training and is done when its completion is recorded there, so any certificate goes on file. Other steps are marked done by the person or by HR.</p>
                <div style="display:flex;gap:8px;"><button type="submit" class="dot-btn dot-btn-primary">{{ $s ? 'Save' : 'Add it' }}</button>@if ($s)<a href="{{ route('learning.paths.show', $path) }}#steps" class="dot-btn dot-btn-ghost">Cancel</a>@endif</div>
            </form>
        @endif
    </section>

    <section class="rg-section" id="people">
        <h2>People on it</h2>
        <div class="dot-card dot-card--pad">
            @forelse ($active as $e)
                @php $c = $counts[$e->id]; @endphp
                <a href="{{ route('learning.enrolments.show', $e) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                    <div style="min-width:0;"><h4>{{ $e->employee->fullName() }}</h4><div class="m">Started {{ $e->started_on->format('j M Y') }}</div></div>
                    <span class="dot-note">{{ $c['done'] }} of {{ $c['total'] }} {{ Str::plural('step', $c['total']) }} done</span>
                </a>
            @empty
                <p class="dot-note" style="margin:0;">Nobody is working through it now.</p>
            @endforelse
            @if ($finished->isNotEmpty())
                <div class="dot-label" style="margin-top:14px;">Completed</div>
                @foreach ($finished as $e)
                    <a href="{{ route('learning.enrolments.show', $e) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                        <div style="min-width:0;"><h4>{{ $e->employee->fullName() }}</h4></div>
                        <span class="dot-note">Completed {{ $e->completed_at->format('j M Y') }}</span>
                    </a>
                @endforeach
            @endif
        </div>

        @if ($steps->isNotEmpty() && ($people->isNotEmpty() || $inPosition > 0))
            <div class="dot-card dot-card--pad" style="margin-top:14px;display:grid;gap:12px;">
                @if ($inPosition > 0)
                    <form method="POST" action="{{ route('learning.enrol-position', $path) }}" style="margin:0;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        @csrf
                        <button type="submit" class="dot-btn dot-btn-primary">Put everyone in {{ $path->position->title }} on it</button>
                        <span class="dot-note">{{ $inPosition }} {{ Str::plural('person', $inPosition) }} in the position {{ $inPosition === 1 ? 'is' : 'are' }} not on it yet.</span>
                    </form>
                @endif
                @if ($people->isNotEmpty())
                    <details>
                        <summary class="dot-btn {{ $inPosition > 0 ? 'dot-btn-ghost' : '' }}" style="list-style:none;display:inline-flex;">Choose people to put on it</summary>
                        <form method="POST" action="{{ route('learning.enrol', $path) }}" style="display:grid;gap:10px;margin-top:10px;">
                            @csrf
                            <fieldset style="border:0;margin:0;padding:0;">
                                <legend class="dot-label">Who</legend>
                                <div class="rg-checks">
                                    @foreach ($people as $person)
                                        <label><input type="checkbox" name="employee_ids[]" value="{{ $person->id }}"> {{ $person->first_name }} {{ $person->last_name }}</label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <div><button type="submit" class="dot-btn dot-btn-primary">Put them on it</button></div>
                        </form>
                    </details>
                @endif
            </div>
        @endif
    </section>

    @if ($isAdmin)
        <section class="rg-section">
            <h2>About this path</h2>
            <form method="POST" action="{{ route('learning.paths.update', $path) }}" class="dot-card dot-card--pad" style="display:grid;gap:12px;max-width:640px;">
                @csrf
                @method('PUT')
                @include('learning._path-fields', ['path' => $path])
                <div><button type="submit" class="dot-btn dot-btn-primary">Save</button></div>
            </form>
            <form method="POST" action="{{ route('learning.paths.retire', $path) }}" style="margin:12px 0 0;" data-confirm="Retire {{ $path->name }}? Nobody new can start it. People on it keep what they did on record.">
                @csrf
                <button type="submit" class="dot-btn dot-btn-ghost">Retire this path</button>
            </form>
        </section>
    @endif
</div>
</x-app-layout>
