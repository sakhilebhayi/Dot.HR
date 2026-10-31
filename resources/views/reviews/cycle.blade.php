<x-app-layout>
<div class="page-pad">
    @php $done = $reviews->where('status', 'acknowledged')->count(); @endphp
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('reviews.index') }}">Reviews</a></p>
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;">
        <div>
            <h1 class="rg-greet">{{ $cycle->name }} — <em>{{ $done }} of {{ $reviews->count() }}</em> complete.</h1>
            <p class="rg-sub" style="margin-bottom:0;">{{ $cycle->status === 'open' ? 'Open' : 'Closed' }}@if ($cycle->closes_on) · aiming to finish by {{ $cycle->closes_on->format('j M Y') }}@endif</p>
        </div>
        @if ($cycle->status === 'open')
            <form method="POST" action="{{ route('reviews.cycle.close', $cycle) }}" onsubmit="return confirm('Close this cycle? Unfinished reviews stay as they are.');">@csrf<button type="submit" class="dot-btn">Close the cycle</button></form>
        @endif
    </div>

    <div style="margin-top:18px;"><x-flash /></div>
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    <div class="dot-card" style="overflow:hidden;">
        <div class="table-scroll">
            <table style="width:100%;">
                <thead><tr><th class="dot-th">Person</th><th class="dot-th">Reviewer</th><th class="dot-th">Where it is</th></tr></thead>
                <tbody>
                    @forelse ($reviews as $review)
                        <tr>
                            <td class="dot-td"><a href="{{ route('reviews.show', $review) }}">{{ $review->employee->fullName() }}</a></td>
                            <td class="dot-td">{{ $review->reviewer->name }}</td>
                            <td class="dot-td"><span class="dot-badge {{ $review->status === 'acknowledged' ? 'dot-badge-ok' : 'dot-badge-muted' }}">{{ \App\Models\Review::STATUSES[$review->status] }}</span></td>
                        </tr>
                    @empty
                        <tr><td class="dot-td dot-note" colspan="3">Nobody is in this cycle yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($cycle->status === 'open' && $people->isNotEmpty())
        <section class="rg-section">
            <h2>Add people</h2>
            <form method="POST" action="{{ route('reviews.cycle.people', $cycle) }}" class="dot-card dot-card--pad" style="display:grid;gap:12px;">
                @csrf
                <div style="max-width:360px;">
                    <label for="reviewer_id">Reviewed by</label>
                    <select id="reviewer_id" name="reviewer_id" class="dot-input" required>
                        @foreach ($members as $member)<option value="{{ $member->id }}" @selected($member->id === auth()->id())>{{ $member->name }}</option>@endforeach
                    </select>
                </div>
                <fieldset style="border:0;padding:0;margin:0;">
                    <legend style="font-size:13px;font-weight:500;margin-bottom:6px;">Who they review</legend>
                    <div class="rg-checks">
                        @foreach ($people as $person)
                            <label><input type="checkbox" name="employee_ids[]" value="{{ $person->id }}"> {{ $person->first_name }} {{ $person->last_name }}</label>
                        @endforeach
                    </div>
                    <p class="dot-note" style="margin:6px 0 0;">People without a Dot.HR account skip their own reflection and go straight to the reviewer. Nobody reviews themselves.</p>
                </fieldset>
                <div><button type="submit" class="dot-btn dot-btn-primary">Add to the cycle</button></div>
            </form>
        </section>
    @endif
</div>
</x-app-layout>
