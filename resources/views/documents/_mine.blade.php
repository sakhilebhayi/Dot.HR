{{-- The employee's own documents (R-32 §10): what HR shared, what they handed in, and the
     company's policies. Anything waiting for them comes first. --}}
<div class="dot-card dot-card--pad" id="documents">
    <div class="dot-label">Your documents</div>
    @php
        $waiting = $myDocuments->filter(fn ($d) => $d->requires_acceptance && ! $myAcks->has($d->id))
            ->concat($policies->filter(fn ($p) => $p->requires_acceptance && ! $myAcks->has($p->id)));
    @endphp
    @if ($waiting->isNotEmpty())
        <p class="dot-note" style="margin:0 0 8px;color:var(--rg-ink);"><b>{{ $waiting->count() }} {{ \Illuminate\Support\Str::plural('document', $waiting->count()) }}</b> {{ $waiting->count() === 1 ? 'asks' : 'ask' }} for your confirmation.</p>
        @foreach ($waiting as $doc)
            <div class="rg-entry" style="display:block;">
                <h4 style="font-size:14px;"><a href="{{ route('documents.download', $doc) }}" target="_blank" rel="noopener">{{ $doc->title }}</a></h4>
                <div class="m">{{ $doc->kindLabel() }} — open it and read it first.</div>
                <form method="POST" action="{{ route('documents.acknowledge', $doc) }}" style="display:grid;gap:8px;margin-top:8px;max-width:420px;">
                    @csrf
                    @if ($doc->isPolicy())
                        <label class="dot-note" style="display:flex;gap:8px;align-items:center;margin:0;"><input type="checkbox" name="confirm" value="1" required> I have read this policy</label>
                    @else
                        <label class="dot-note" style="display:flex;gap:8px;align-items:center;margin:0;"><input type="checkbox" name="confirm" value="1" required> I have read it and I accept it</label>
                        <div><label for="typed-{{ $doc->id }}">Type your full name to accept</label><input id="typed-{{ $doc->id }}" name="typed_name" class="dot-input" maxlength="120" required placeholder="{{ $employee->fullName() }}"></div>
                        <p class="dot-note" style="margin:0;">Your name and the time are recorded as your electronic acceptance. This is not an advanced electronic signature.</p>
                    @endif
                    <div><button type="submit" class="dot-btn dot-btn-primary">{{ $doc->isPolicy() ? 'Confirm' : 'Accept' }}</button></div>
                </form>
            </div>
        @endforeach
    @endif

    @php
        $settled = $myDocuments->reject(fn ($d) => $waiting->contains('id', $d->id));
    @endphp
    @if ($settled->isEmpty() && $waiting->isEmpty())
        <p class="dot-note" style="margin:0 0 8px;">HR has not shared any documents with you yet.</p>
    @endif
    @foreach ($settled as $doc)
        <a href="{{ route('documents.download', $doc) }}" target="_blank" rel="noopener" class="rg-entry" style="text-decoration:none;color:inherit;">
            <div><h4 style="font-size:14px;">{{ $doc->title }}</h4><div class="m">{{ $doc->kindLabel() }}, {{ $doc->source === 'employee' ? 'you handed it in' : 'from HR' }}, {{ $doc->created_at->format('j M Y') }}</div></div>
            @if ($myAcks->has($doc->id))<span class="dot-badge dot-badge-ok">Accepted</span>@endif
        </a>
    @endforeach

    @if ($policies->isNotEmpty())
        <p class="dot-note" style="margin:12px 0 4px;"><a href="{{ route('policies.index') }}">Company policies</a>: {{ $policies->count() }}, {{ $policies->filter(fn ($p) => $myAcks->has($p->id))->count() }} confirmed by you.</p>
    @endif

    <details style="margin-top:10px;">
        <summary class="dot-btn dot-btn-ghost" style="list-style:none;display:inline-flex;">Hand a document in</summary>
        <form method="POST" action="{{ route('my-work.documents.store') }}" enctype="multipart/form-data" style="display:grid;gap:8px;margin-top:8px;">
            @csrf
            <div><label for="sub-kind">What it is</label><select id="sub-kind" name="kind" class="dot-input">@foreach (\App\Models\HrDocument::SUBMITTABLE as $k)<option value="{{ $k }}">{{ \App\Models\HrDocument::KINDS[$k] }}</option>@endforeach</select></div>
            <div><label for="sub-title">Title</label><input id="sub-title" name="title" class="dot-input" minlength="3" maxlength="160" required placeholder="Matric certificate"></div>
            <div><label for="sub-file">File</label><input id="sub-file" name="file" type="file" class="dot-input" required accept="application/pdf,.doc,.docx,image/jpeg,image/png"></div>
            <p class="dot-note" style="margin:0;">Only HR administrators see what you hand in — not your manager.</p>
            <div><button type="submit" class="dot-btn">Hand it in</button></div>
        </form>
    </details>
</div>
