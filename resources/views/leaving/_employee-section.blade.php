{{-- Leaving on a person's record: HR administrators only. --}}
<div class="dot-card dot-card--pad" id="leaving" style="margin-top:14px;">
    <div class="dot-label">Leaving</div>
    @if ($leavingInfo['resignation'])
        @php $r = $leavingInfo['resignation']; @endphp
        <p style="margin:6px 0 0;">Resigned {{ $r->given_at->format('j M Y') }}, last day {{ $r->leavingOn()->format('j M Y') }}. {{ \App\Models\Resignation::STATUSES[$r->status] }}.</p>
    @endif
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
        @if ($leavingInfo['interview'])
            <a href="{{ route('leaving.interview', $leavingInfo['interview']) }}" class="dot-btn">Exit interview</a>
        @else
            <form method="POST" action="{{ route('leaving.offer-interview', $employee) }}" style="margin:0;">@csrf<button type="submit" class="dot-btn dot-btn-ghost">Offer an exit interview</button></form>
        @endif
        @foreach ($leavingInfo['certificates'] as $certificate)
            <a href="{{ route('leaving.certificate', $certificate) }}" class="dot-btn">Certificate of {{ $certificate->issued_at->format('j M Y') }}</a>
        @endforeach
        <a href="{{ route('leaving.certificate.create', $employee) }}" class="dot-btn dot-btn-ghost">{{ $leavingInfo['certificates']->isEmpty() ? 'Issue a certificate of service' : 'Issue another' }}</a>
    </div>
</div>
