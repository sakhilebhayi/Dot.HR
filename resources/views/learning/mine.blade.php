<x-app-layout>
<div class="page-pad" style="max-width:900px;">
    @php $going = $mine->filter->isActive(); @endphp
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('my-work.index') }}">My work</a></p>
    <h1 class="rg-greet">
        @if ($going->isNotEmpty())
            Your learning: <em>{{ $going->count() }} {{ Str::plural('path', $going->count()) }}</em> in progress.
        @else
            Your learning.
        @endif
    </h1>
    <p class="rg-sub">The order to learn your work in, step by step. Mark a step done when you have done it; courses are booked through HR and count once they are completed. Only you and HR see your progress.</p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    <div class="dot-card dot-card--pad">
        @forelse ($mine as $e)
            @php $c = $counts[$e->id]; @endphp
            <a href="{{ route('my-work.learning.show', $e) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                <div style="min-width:0;">
                    <h4>{{ $e->path->name }}</h4>
                    <div class="m">Started {{ $e->started_on->format('j M Y') }}</div>
                    @if ($e->isActive() && $c['total'] > 0)<div class="rg-track" style="max-width:260px;margin-top:8px;" role="img" aria-label="{{ $c['done'] }} of {{ $c['total'] }} steps done"><i style="width:{{ round($c['done'] / $c['total'] * 100) }}%;"></i></div>@endif
                </div>
                @if ($e->completed_at)
                    <span class="dot-badge dot-badge-ok">Completed {{ $e->completed_at->format('j M Y') }}</span>
                @else
                    <span class="dot-note">{{ $c['done'] }} of {{ $c['total'] }} done</span>
                @endif
            </a>
        @empty
            <p class="dot-note" style="margin:0;">You are not on a learning path yet.{{ $open->isNotEmpty() ? ' You can start one of the paths below.' : ' HR puts people on paths for their position.' }}</p>
        @endforelse
    </div>

    @if ($open->isNotEmpty())
        <section class="rg-section">
            <h2>Paths you can start</h2>
            <div class="dot-card dot-card--pad">
                @foreach ($open as $path)
                    <div class="rg-entry rg-entry--tools" style="align-items:center;">
                        <div style="min-width:0;"><h4>{{ $path->name }}</h4><div class="m">{{ $path->steps_count }} {{ Str::plural('step', $path->steps_count) }}.@if ($path->description) {{ $path->description }}@endif</div></div>
                        <form method="POST" action="{{ route('my-work.learning.join', $path) }}" style="margin:0;display:flex;">@csrf<button type="submit" class="dot-btn">Start it</button></form>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
</x-app-layout>
