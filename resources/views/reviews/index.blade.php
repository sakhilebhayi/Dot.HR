<x-app-layout>
<div class="page-pad">
    @php $waitingOnMe = $mine->where('status', 'reviewer')->count(); @endphp
    <h1 class="rg-greet">
        @if ($waitingOnMe > 0)
            <em>{{ $waitingOnMe }} {{ Str::plural('review', $waitingOnMe) }}</em> waiting for your feedback.
        @elseif ($isAdmin && $cycles->isEmpty())
            No review cycle has been run yet.
        @else
            Nothing is waiting for your feedback.
        @endif
    </h1>
    <p class="rg-sub">Written reviews: the person reflects, the reviewer writes back, the person reads it and answers. There are no scores and no ranking — only what each of you writes, and the goals you agree.</p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    @if ($mine->isNotEmpty())
        <section class="rg-section" style="margin-top:0;">
            <h2>Reviews you write</h2>
            <div class="dot-card dot-card--pad">
                @foreach ($mine as $review)
                    <a href="{{ route('reviews.show', $review) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                        <div style="min-width:0;"><h4>{{ $review->employee->fullName() }}</h4><div class="m">{{ $review->cycle->name }}</div></div>
                        <span class="dot-badge {{ $review->status === 'reviewer' ? 'dot-badge-accent' : 'dot-badge-muted' }}">{{ \App\Models\Review::STATUSES[$review->status] }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($isAdmin)
        <section class="rg-section">
            <h2>Review cycles</h2>
            <div class="dot-card dot-card--pad">
                @forelse ($cycles as $cycle)
                    <a href="{{ route('reviews.cycle', $cycle) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                        <div style="min-width:0;">
                            <h4>{{ $cycle->name }}</h4>
                            <div class="m">{{ $cycle->done_count }} of {{ $cycle->reviews_count }} complete @if ($cycle->closes_on) · closes {{ $cycle->closes_on->format('j M Y') }} @endif</div>
                            <div class="rg-track" style="margin-top:6px;max-width:260px;"><i style="width: {{ $cycle->reviews_count ? round($cycle->done_count / $cycle->reviews_count * 100) : 0 }}%"></i></div>
                        </div>
                        <span class="dot-badge {{ $cycle->status === 'open' ? 'dot-badge-accent' : 'dot-badge-muted' }}">{{ $cycle->status === 'open' ? 'Open' : 'Closed' }}</span>
                    </a>
                @empty
                    <p class="dot-note" style="margin:0;">Start a cycle, then add the people it covers and who reviews each of them.</p>
                @endforelse
            </div>

            <form method="POST" action="{{ route('reviews.cycles.store') }}" class="dot-card dot-card--pad rg-form-row" style="margin-top:14px;align-items:end;">
                @csrf
                <div><label for="name">New cycle</label><input id="name" name="name" class="dot-input" maxlength="120" required placeholder="Second half 2026"></div>
                <div style="display:flex;gap:6px;align-items:end;">
                    <div style="flex:1;"><label for="closes_on">Aim to finish by</label><input id="closes_on" name="closes_on" type="date" class="dot-input"></div>
                    <button type="submit" class="dot-btn dot-btn-primary">Start</button>
                </div>
            </form>
        </section>
    @endif
</div>
</x-app-layout>
