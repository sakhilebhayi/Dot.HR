<x-app-layout>
<div class="page-pad" style="max-width:960px;">
    @php $working = $active->sum(); @endphp
    <h1 class="rg-greet">
        @if ($paths->isEmpty())
            No learning paths yet.
        @else
            <em>{{ $paths->count() }} learning {{ Str::plural('path', $paths->count()) }}</em>{{ $working ? ', '.$working.' '.Str::plural('person', $working).' working through them.' : '.' }}
        @endif
    </h1>
    <p class="rg-sub">A path is the order someone learns a job in: the courses the business books, the sessions someone here runs, what to read and what to practise. Progress is the steps done. It is never a score, and nobody is compared with anyone else.</p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    <div class="dot-card dot-card--pad">
        @forelse ($paths as $path)
            @php $on = $active->get($path->id, 0); $done = $finished->get($path->id, 0); @endphp
            <a href="{{ route('learning.paths.show', $path) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                <div style="min-width:0;">
                    <h4>{{ $path->name }}</h4>
                    <div class="m">{{ $path->steps_count }} {{ Str::plural('step', $path->steps_count) }}{{ $path->position ? ', for the '.$path->position->title.' position' : '' }}.{{ $path->self_enrol ? ' Anyone may start it.' : '' }}</div>
                </div>
                <span class="dot-note">{{ $on || $done ? trim(($on ? $on.' in progress' : '').($on && $done ? ', ' : '').($done ? $done.' completed' : '')) : 'Nobody on it yet' }}</span>
            </a>
        @empty
            <p class="dot-note" style="margin:0;">{{ $isAdmin ? 'Start with the job people most often learn here, such as a new cashier’s first month.' : 'HR has not made a learning path yet.' }}</p>
        @endforelse
    </div>

    @if ($isAdmin)
        <section class="rg-section" id="new">
            <h2>Make a path</h2>
            <form method="POST" action="{{ route('learning.paths.store') }}" class="dot-card dot-card--pad" style="display:grid;gap:12px;max-width:640px;">
                @csrf
                @include('learning._path-fields', ['path' => null])
                <div><button type="submit" class="dot-btn dot-btn-primary">Make it, then add steps</button></div>
            </form>
        </section>
    @endif
</div>
</x-app-layout>
