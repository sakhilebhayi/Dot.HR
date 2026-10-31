<x-app-layout>
<div class="page-pad">
    @php $open = $requests->where('status', 'open'); @endphp
    <h1 class="rg-greet">
        @if ($open->isEmpty())
            No requests are waiting for HR.
        @else
            <em>{{ $open->count() }} {{ Str::plural('request', $open->count()) }}</em> waiting for an answer.
        @endif
    </h1>
    <p class="rg-sub">What your people asked HR. Only HR administrators see these — never managers or colleagues.</p>

    <x-flash />

    <div class="dot-card dot-card--pad">
        @forelse ($requests as $hrRequest)
            <a href="{{ route('requests.show', $hrRequest) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                <div style="min-width:0;">
                    <h4>{{ $hrRequest->subject }}</h4>
                    <div class="m">{{ $hrRequest->employee?->fullName() ?? 'Former employee' }} · {{ explode(' (', \App\Models\HrRequest::CATEGORIES[$hrRequest->category] ?? $hrRequest->category)[0] }} · {{ $hrRequest->reference() }} · {{ $hrRequest->created_at->diffForHumans() }}</div>
                </div>
                <span class="dot-badge {{ $hrRequest->status === 'open' ? 'dot-badge-warn' : ($hrRequest->status === 'answered' ? 'dot-badge-ok' : 'dot-badge-muted') }}">{{ \App\Models\HrRequest::STATUSES[$hrRequest->status] }}</span>
            </a>
        @empty
            <p class="dot-note" style="margin:0;">Nothing yet. Employees raise requests from My work.</p>
        @endforelse
    </div>
</div>
</x-app-layout>
