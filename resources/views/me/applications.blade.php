<x-app-layout>
<div class="page-pad">
    @php
        $active = $applications->filter->isOpen();
        $offers = $applications->filter(fn ($a) => $a->offers->where('status', 'sent')->isNotEmpty());
        $upcoming = $applications->flatMap->interviews->where('status', 'scheduled')->filter(fn ($i) => $i->starts_at->isFuture());
    @endphp
    <h1 class="rg-greet">
        @if ($applications->isEmpty())
            You haven't applied anywhere yet.
        @elseif ($offers->isNotEmpty())
            <em>{{ $offers->count() }} {{ Str::plural('offer', $offers->count()) }}</em> {{ $offers->count() === 1 ? 'is' : 'are' }} waiting for your answer.
        @elseif ($upcoming->isNotEmpty())
            <em>{{ $upcoming->count() }} {{ Str::plural('interview', $upcoming->count()) }}</em> coming up.
        @else
            <em>{{ $active->count() }}</em> {{ Str::plural('application', $active->count()) }} in progress.
        @endif
    </h1>
    <p class="rg-sub">Every business you applied to, and exactly where you stand with each.</p>

    <x-flash />

    @if ($applications->isEmpty())
        <a href="{{ route('jobs.index') }}" class="dot-btn dot-btn-primary">Browse open roles</a>
    @else
        <div class="dot-card dot-card--pad">
            @foreach ($applications as $application)
                @php $next = $application->interviews->where('status', 'scheduled')->filter(fn ($i) => $i->starts_at->isFuture())->first(); @endphp
                <a href="{{ route('me.applications.show', $application->id) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                    <div style="min-width:0;">
                        <h4>{{ $application->vacancy->title }} — {{ $application->vacancy->team->name }}</h4>
                        <div class="m">
                            Applied {{ $application->created_at->format('j M Y') }}
                            @if ($next) · interview {{ $next->starts_at->format('D j M, H:i') }} @endif
                        </div>
                    </div>
                    <span class="dot-badge {{ in_array($application->stage, ['offer', 'hired'], true) ? 'dot-badge-ok' : ($application->isOpen() ? 'dot-badge-accent' : 'dot-badge-muted') }}">{{ $application->candidateStageLabel() }}</span>
                </a>
            @endforeach
        </div>
    @endif
</div>
</x-app-layout>
