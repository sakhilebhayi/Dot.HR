<x-app-layout>
<div class="page-pad">
    <h1 class="rg-greet">
        @if ($policies->isEmpty())
            No company policies yet.
        @else
            <em>{{ $policies->count() }} {{ \Illuminate\Support\Str::plural('policy', $policies->count()) }}</em> {{ $admin ? 'published' : 'for everyone here' }}.
        @endif
    </h1>
    <p class="rg-sub">{{ $admin ? 'Everyone with a linked record can open them. You see who has confirmed reading each one.' : 'Open each one. Where a policy asks, confirm that you have read it.' }}</p>

    @if (session('status'))<div class="dot-flash">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="dot-flash dot-flash-err">{{ $errors->first() }}</div>@endif

    <div class="dot-card dot-card--pad">
        @forelse ($policies as $policy)
            <div class="rg-entry rg-entry--tools">
                <div>
                    <h4 style="font-size:14px;"><a href="{{ route('documents.download', $policy) }}" target="_blank" rel="noopener">{{ $policy->title }}</a></h4>
                    <div class="m">Published {{ $policy->created_at->format('j M Y') }}, {{ $policy->sizeLabel() }}@if ($policy->requires_acceptance) — asks people to confirm they have read it @endif</div>
                </div>
                @if ($admin)
                    @if ($policy->withdrawn_at)
                        <span class="dot-badge dot-badge-muted">Withdrawn</span>
                    @else
                        @if ($policy->requires_acceptance)<span class="dot-badge {{ $policy->acknowledgements_count >= $linked && $linked > 0 ? 'dot-badge-ok' : 'dot-badge-warn' }}">{{ $policy->acknowledgements_count }} of {{ $linked }} confirmed</span>@endif
                        <form method="POST" action="{{ route('documents.withdraw', $policy) }}" style="margin:0;">@csrf<button type="submit" class="dot-btn dot-btn-ghost">Withdraw</button></form>
                    @endif
                @elseif ($policy->requires_acceptance)
                    @if ($mine->has($policy->id))
                        <span class="dot-badge dot-badge-ok">Read {{ \Illuminate\Support\Carbon::parse($mine->get($policy->id))->format('j M') }}</span>
                    @else
                        <form method="POST" action="{{ route('documents.acknowledge', $policy) }}" style="display:flex;gap:8px;align-items:center;margin:0;">
                            @csrf
                            <label class="dot-note" style="display:flex;gap:6px;align-items:center;margin:0;"><input type="checkbox" name="confirm" value="1" required> I have read it</label>
                            <button type="submit" class="dot-btn dot-btn-primary">Confirm</button>
                        </form>
                    @endif
                @endif
            </div>
        @empty
            <p class="dot-note" style="margin:0;">{{ $admin ? 'Publish your leave, conduct or safety policy here so everyone can find it in one place.' : 'HR has not published any policies yet.' }}</p>
        @endforelse
    </div>

    @if ($admin)
        <div class="dot-card dot-card--pad" style="margin-top:14px;">
            <div class="dot-label">Publish a policy</div>
            <form method="POST" action="{{ route('policies.store') }}" enctype="multipart/form-data" style="display:grid;gap:10px;max-width:520px;">
                @csrf
                <div><label for="p-title">Title</label><input id="p-title" name="title" class="dot-input" minlength="3" maxlength="160" required placeholder="Leave policy"></div>
                <div><label for="p-file">File</label><input id="p-file" name="file" type="file" class="dot-input" required accept="application/pdf,.doc,.docx"></div>
                <label class="dot-note" style="display:flex;gap:8px;align-items:center;margin:0;"><input type="checkbox" name="requires_acceptance" value="1"> Ask everyone to confirm they have read it</label>
                <div><button type="submit" class="dot-btn dot-btn-primary">Publish</button></div>
            </form>
        </div>
    @endif
</div>
</x-app-layout>
