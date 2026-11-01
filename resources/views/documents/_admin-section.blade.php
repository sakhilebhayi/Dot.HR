{{-- Documents on one employee's record, for HR administrators (R-32 §10). Managers never see this. --}}
<div class="dot-card" id="documents" style="padding:1.5rem;margin-top:1.5rem;">
    <div style="font-size:0.95rem;font-weight:600;color:var(--rg-ink);margin-bottom:0.25rem;">Documents</div>
    <p class="dot-note" style="margin:0 0 0.75rem;">Kept encrypted in Dot.HR. Only HR administrators see them; {{ $employee->first_name }} sees what you share and what they hand in.</p>

    @forelse ($documents as $doc)
        @php
            $ack = $doc->acknowledgements->first();
        @endphp
        <div class="rg-entry rg-entry--tools">
            <div>
                <h4 style="font-size:14px;"><a href="{{ route('documents.download', $doc) }}" target="_blank" rel="noopener">{{ $doc->title }}</a></h4>
                <div class="m">
                    {{ $doc->kindLabel() }}, {{ $doc->sizeLabel() }}, {{ $doc->source === 'employee' ? 'handed in by '.$employee->first_name : 'filed' }} {{ $doc->created_at->format('j M Y') }}
                    @if ($ack) — accepted {{ $ack->acknowledged_at->format('j M Y, H:i') }} as “{{ $ack->typed_name }}”@endif
                </div>
            </div>
            @if ($doc->withdrawn_at)
                <span class="dot-badge dot-badge-muted">Withdrawn</span>
            @else
                <span class="dot-badge {{ $ack ? 'dot-badge-ok' : ($doc->requires_acceptance ? 'dot-badge-warn' : ($doc->shared ? 'dot-badge-accent' : 'dot-badge-muted')) }}">{{ $ack ? 'Accepted' : ($doc->requires_acceptance ? 'Waiting for acceptance' : ($doc->shared ? 'Shared' : 'HR only')) }}</span>
                @if ($doc->source === 'hr' && ! $ack)
                    <details>
                        <summary class="dot-btn dot-btn-ghost" style="list-style:none;">{{ $doc->shared ? 'Ask to accept' : 'Share' }}</summary>
                        <form method="POST" action="{{ route('documents.share', $doc) }}" style="display:grid;gap:8px;margin-top:8px;min-width:240px;">
                            @csrf
                            @if ($employee->user_id)
                                <label class="dot-note" style="display:flex;gap:8px;align-items:center;margin:0;"><input type="checkbox" name="requires_acceptance" value="1" @checked($doc->shared)> Ask {{ $employee->first_name }} to accept it</label>
                                <button type="submit" class="dot-btn dot-btn-primary">{{ $doc->shared ? 'Ask for acceptance' : 'Share' }}</button>
                            @else
                                <p class="dot-note" style="margin:0;">Link {{ $employee->first_name }}’s account first, so they can open it.</p>
                            @endif
                        </form>
                    </details>
                @endif
                <form method="POST" action="{{ route('documents.withdraw', $doc) }}" style="margin:0;">
                    @csrf
                    <button type="submit" class="dot-btn dot-btn-ghost">Withdraw</button>
                </form>
            @endif
        </div>
    @empty
        <p class="dot-note" style="margin:0 0 0.5rem;">Nothing filed yet.</p>
    @endforelse

    <details style="margin-top:12px;">
        <summary class="dot-btn" style="list-style:none;display:inline-flex;">File a document</summary>
        <form method="POST" action="{{ route('employees.documents.store', $employee) }}" enctype="multipart/form-data" style="display:grid;gap:10px;margin-top:10px;max-width:520px;">
            @csrf
            <div><label class="dot-label" for="doc_kind">What it is</label>
                <select id="doc_kind" name="kind" class="dot-input" required>@foreach (\App\Models\HrDocument::KINDS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
            <div><label class="dot-label" for="doc_title">Title</label><input id="doc_title" name="title" class="dot-input" minlength="3" maxlength="160" required placeholder="Employment contract, 2026"></div>
            <div><label class="dot-label" for="doc_file">File</label><input id="doc_file" name="file" type="file" class="dot-input" required accept="application/pdf,.doc,.docx,image/jpeg,image/png">
                <p class="dot-note" style="margin:4px 0 0;">PDF, Word, JPEG or PNG, up to {{ \App\Models\HrDocument::MAX_BYTES / 1048576 }} MB.</p></div>
            @if ($employee->user_id)
                <label class="dot-note" style="display:flex;gap:8px;align-items:center;margin:0;"><input type="checkbox" name="share" value="1"> Share with {{ $employee->first_name }}</label>
                <label class="dot-note" style="display:flex;gap:8px;align-items:center;margin:0;"><input type="checkbox" name="requires_acceptance" value="1"> Ask {{ $employee->first_name }} to accept it (shares it too)</label>
            @else
                <p class="dot-note" style="margin:0;">{{ $employee->first_name }} has no linked account yet, so this stays with HR until you link one.</p>
            @endif
            <div><button type="submit" class="dot-btn dot-btn-primary">File it</button></div>
        </form>
    </details>
</div>
