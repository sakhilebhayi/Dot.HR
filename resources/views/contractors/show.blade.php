<x-app-layout>
<div class="page-pad">
    @php
        $e = $engagement;
        $waiting = $e->milestones->where('status', 'submitted')->count();
    @endphp
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('contractors.index') }}">Contractors</a></p>
    <h1 class="rg-greet">
        {{ $e->employee->fullName() }} — <em>{{ $e->title }}</em>
    </h1>
    <p class="rg-sub">
        {{ \App\Models\Engagement::KINDS[$e->kind] }} · {{ $e->rate() }} ·
        @if ($e->status === 'ended')
            ended {{ $e->ended_on?->format('j M Y') }} ({{ strtolower(\App\Models\Engagement::END_REASONS[$e->end_reason] ?? '') }})
        @else
            {{ $e->starts_on->format('j M Y') }} to {{ $e->ends_on?->format('j M Y') ?? 'open-ended' }}@if ($e->renewals) · renewed {{ $e->renewals }}× @endif
        @endif
    </p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    <div class="rg-grid">
        <div class="s8" style="display:grid;gap:14px;align-content:start;">
            @if ($e->scope)
                <div class="dot-card dot-card--pad">
                    <div class="dot-label">Scope of work</div>
                    <p style="margin:6px 0 0;white-space:pre-line;">{{ $e->scope }}</p>
                </div>
            @endif

            <div class="dot-card dot-card--pad">
                <div class="dot-label">Milestones{{ $waiting ? ' — '.$waiting.' delivered, waiting for you' : '' }}</div>
                @forelse ($e->milestones as $milestone)
                    <div class="rg-entry rg-entry--tools" style="align-items:flex-start;">
                        <div style="min-width:0;">
                            <h4>{{ $milestone->title }}</h4>
                            <div class="m">
                                @if ($milestone->due_on) due {{ $milestone->due_on->format('j M Y') }} @endif
                                @if ($milestone->amount !== null) · {{ $e->currency }} {{ number_format((float) $milestone->amount, 2) }} @endif
                                @if ($milestone->decision_note) · {{ $milestone->decision_note }} @endif
                            </div>
                        </div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;align-items:center;">
                            @if ($milestone->status === 'submitted')
                                <form method="POST" action="{{ route('milestones.decide', [$milestone, 'approve']) }}" style="margin:0;">@csrf<button type="submit" class="dot-btn dot-btn-primary">Approve</button></form>
                                <details>
                                    <summary class="dot-btn dot-btn-ghost" style="list-style:none;">Send back</summary>
                                    <form method="POST" action="{{ route('milestones.decide', [$milestone, 'reject']) }}" style="display:flex;gap:6px;margin-top:6px;">
                                        @csrf
                                        <input name="note" class="dot-input" maxlength="255" required placeholder="What still needs doing" aria-label="What still needs doing">
                                        <button type="submit" class="dot-btn">Send back</button>
                                    </form>
                                </details>
                            @else
                                <span class="dot-badge {{ ['open' => 'dot-badge-muted', 'approved' => 'dot-badge-ok', 'rejected' => 'dot-badge-warn'][$milestone->status] }}">{{ \App\Models\EngagementMilestone::STATUSES[$milestone->status] }}</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="dot-note" style="margin:6px 0 0;">No milestones. Add the deliverables the contractor marks as done.</p>
                @endforelse
                @if ($e->status === 'active')
                    <form method="POST" action="{{ route('contractors.milestones.store', $e) }}" class="rg-form-row rg-form-row--3" style="margin-top:12px;align-items:end;">
                        @csrf
                        <div><label for="m-title">Deliverable</label><input id="m-title" name="title" class="dot-input" maxlength="160" required></div>
                        <div><label for="m-due">Due</label><input id="m-due" name="due_on" type="date" class="dot-input"></div>
                        <div style="display:flex;gap:6px;align-items:end;">
                            <div style="flex:1;"><label for="m-amount">Amount</label><input id="m-amount" name="amount" type="number" step="0.01" min="0" class="dot-input"></div>
                            <button type="submit" class="dot-btn">Add</button>
                        </div>
                    </form>
                @endif
            </div>

            <div class="dot-card dot-card--pad">
                <div class="dot-label">Invoices received</div>
                @forelse ($e->invoices as $invoice)
                    <div class="rg-entry rg-entry--tools" style="align-items:flex-start;">
                        <div style="min-width:0;">
                            <h4>{{ $invoice->reference }} · {{ $e->currency }} {{ number_format((float) $invoice->amount, 2) }}</h4>
                            <div class="m">received {{ $invoice->received_on->format('j M Y') }}@if ($invoice->note) · {{ $invoice->note }}@endif</div>
                        </div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;align-items:center;">
                            <span class="dot-badge {{ ['received' => 'dot-badge-muted', 'approved' => 'dot-badge-accent', 'disputed' => 'dot-badge-bad', 'paid' => 'dot-badge-ok'][$invoice->status] }}">{{ \App\Models\ContractorInvoice::STATUSES[$invoice->status] }}</span>
                            @foreach (\App\Models\ContractorInvoice::MOVES[$invoice->status] as $to)
                                @if ($to === 'disputed')
                                    <details>
                                        <summary class="dot-btn dot-btn-ghost" style="list-style:none;min-height:32px;">Dispute</summary>
                                        <form method="POST" action="{{ route('invoices.move', $invoice) }}" style="display:flex;gap:6px;margin-top:6px;">
                                            @csrf
                                            <input type="hidden" name="status" value="disputed">
                                            <input name="note" class="dot-input" maxlength="255" required placeholder="What is disputed" aria-label="What is disputed">
                                            <button type="submit" class="dot-btn">Dispute</button>
                                        </form>
                                    </details>
                                @else
                                    <form method="POST" action="{{ route('invoices.move', $invoice) }}" style="margin:0;">
                                        @csrf
                                        <input type="hidden" name="status" value="{{ $to }}">
                                        <button type="submit" class="dot-btn" style="min-height:32px;">{{ $to === 'paid' ? 'We paid it' : 'Approve for payment' }}</button>
                                    </form>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="dot-note" style="margin:6px 0 0;">No invoices recorded yet.</p>
                @endforelse
                <form method="POST" action="{{ route('contractors.invoices.store', $e) }}" class="rg-form-row rg-form-row--3" style="margin-top:12px;align-items:end;">
                    @csrf
                    <div><label for="i-ref">Their invoice number</label><input id="i-ref" name="reference" class="dot-input" maxlength="60" required></div>
                    <div><label for="i-amount">Amount</label><input id="i-amount" name="amount" type="number" step="0.01" min="0" class="dot-input" required></div>
                    <div style="display:flex;gap:6px;align-items:end;">
                        <div style="flex:1;"><label for="i-received">Received</label><input id="i-received" name="received_on" type="date" class="dot-input" required value="{{ \App\Support\Hr\LocalTime::today()->toDateString() }}"></div>
                        <button type="submit" class="dot-btn">Record</button>
                    </div>
                </form>
                <p class="dot-note" style="margin:8px 0 0;">Dot.HR keeps the record; it does not pay invoices. Mark one paid after you have paid it.</p>
            </div>
        </div>

        <div class="s4" style="display:grid;gap:14px;align-content:start;">
            <div class="dot-card dot-card--pad">
                <div class="dot-label">Worth a second look</div>
                @forelse ($signals as $signal)
                    <p style="margin:8px 0 0;">{{ $signal }}</p>
                @empty
                    <p class="dot-note" style="margin:6px 0 0;">Nothing in the record suggests this looks like employment.</p>
                @endforelse
                <p class="dot-note" style="margin:10px 0 0;">{{ config('hr.classification_notice') }}</p>
            </div>

            @if ($e->status === 'active')
                <div class="dot-card dot-card--pad">
                    <div class="dot-label">Renew</div>
                    <form method="POST" action="{{ route('contractors.renew', $e) }}" style="display:flex;gap:6px;align-items:end;margin-top:6px;">
                        @csrf
                        <div style="flex:1;"><label for="r-end">New end date</label><input id="r-end" name="ends_on" type="date" class="dot-input" required></div>
                        <button type="submit" class="dot-btn">Renew</button>
                    </form>
                </div>
                <div class="dot-card dot-card--pad">
                    <div class="dot-label">End the engagement</div>
                    <form method="POST" action="{{ route('contractors.end', $e) }}" style="display:grid;gap:8px;margin-top:6px;" onsubmit="return confirm('End this engagement?');">
                        @csrf
                        <div>
                            <label for="end_reason">Why</label>
                            <select id="end_reason" name="end_reason" class="dot-input" required>
                                @foreach (\App\Models\Engagement::END_REASONS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select>
                        </div>
                        <div><label for="ended_on">Last day</label><input id="ended_on" name="ended_on" type="date" class="dot-input" value="{{ \App\Support\Hr\LocalTime::today()->toDateString() }}"></div>
                        <div><button type="submit" class="dot-btn dot-btn-danger">End it</button></div>
                    </form>
                </div>
            @endif

            @if ($history->isNotEmpty())
                <div class="dot-card dot-card--pad">
                    <div class="dot-label">Their other engagements</div>
                    @foreach ($history as $other)
                        <a href="{{ route('contractors.show', $other) }}" class="rg-entry" style="text-decoration:none;color:inherit;"><div style="min-width:0;"><h4>{{ $other->title }}</h4><div class="m">{{ $other->starts_on->format('M Y') }} · {{ $other->status === 'active' ? 'running' : 'ended' }}</div></div></a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
</x-app-layout>
