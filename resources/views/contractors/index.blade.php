<x-app-layout>
<div class="page-pad">
    <h1 class="rg-greet">
        @if ($active->isEmpty())
            No contractors are working with you right now.
        @else
            <em>{{ $active->count() }} {{ Str::plural('engagement', $active->count()) }}</em> running{{ $endingSoon->isNotEmpty() ? ',' : '.' }}
            @if ($endingSoon->isNotEmpty()) <em>{{ $endingSoon->count() }}</em> ending within {{ \App\Models\Engagement::SOON_DAYS }} days. @endif
        @endif
    </h1>
    <p class="rg-sub">Contractors, freelancers, consultants and temporary workers — their scope, rates, milestones and invoices. Only HR administrators see this page.</p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    @if ($delivered->isNotEmpty() || $invoicesOpen->isNotEmpty())
        <div class="rg-grid" style="margin-bottom:14px;">
            @if ($delivered->isNotEmpty())
                <div class="dot-card dot-card--pad s6">
                    <div class="dot-label">Delivered, waiting for you</div>
                    @foreach ($delivered as $milestone)
                        <a href="{{ route('contractors.show', $milestone->engagement) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                            <div style="min-width:0;"><h4>{{ $milestone->title }}</h4><div class="m">{{ $milestone->engagement->employee->fullName() }} — {{ $milestone->engagement->title }}</div></div>
                            <span class="dot-note">{{ $milestone->submitted_at?->diffForHumans() }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
            @if ($invoicesOpen->isNotEmpty())
                <div class="dot-card dot-card--pad s6">
                    <div class="dot-label">Invoices not yet paid</div>
                    @foreach ($invoicesOpen as $invoice)
                        <a href="{{ route('contractors.show', $invoice->engagement) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                            <div style="min-width:0;"><h4>{{ $invoice->reference }} · {{ $invoice->engagement->currency }} {{ number_format((float) $invoice->amount, 2) }}</h4><div class="m">{{ $invoice->engagement->employee->fullName() }} · received {{ $invoice->received_on->format('j M') }}</div></div>
                            <span class="dot-badge {{ $invoice->status === 'disputed' ? 'dot-badge-bad' : ($invoice->status === 'approved' ? 'dot-badge-accent' : 'dot-badge-muted') }}">{{ \App\Models\ContractorInvoice::STATUSES[$invoice->status] }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    <div class="dot-card dot-card--pad">
        @forelse ($active as $engagement)
            <a href="{{ route('contractors.show', $engagement) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                <div style="min-width:0;">
                    <h4>{{ $engagement->employee->fullName() }} — {{ $engagement->title }}</h4>
                    <div class="m">{{ \App\Models\Engagement::KINDS[$engagement->kind] }} · {{ $engagement->rate() }} · from {{ $engagement->starts_on->format('j M Y') }}</div>
                </div>
                @if ($engagement->ends_on)
                    <span class="dot-badge {{ $engagement->endsSoon() ? 'dot-badge-warn' : 'dot-badge-muted' }}">ends {{ $engagement->ends_on->format('j M Y') }}</span>
                @else
                    <span class="dot-badge dot-badge-muted">open-ended</span>
                @endif
            </a>
        @empty
            <p class="dot-note" style="margin:0;">Start an engagement below. A contractor is a person record whose employment type is "Contractor"; you can add one here.</p>
        @endforelse
    </div>

    <section class="rg-section" id="new">
        <h2>Start an engagement</h2>
        <form method="POST" action="{{ route('contractors.store') }}" class="dot-card dot-card--pad" style="display:grid;gap:12px;">
            @csrf
            <div class="rg-form-row">
                <div>
                    <label for="employee_id">Who</label>
                    <select id="employee_id" name="employee_id" class="dot-input">
                        <option value="">A new contractor (fill in the name)</option>
                        @foreach ($contractors as $person)
                            <option value="{{ $person->id }}" @selected((string) old('employee_id') === (string) $person->id)>{{ $person->first_name }} {{ $person->last_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="kind">What kind of work arrangement</label>
                    <select id="kind" name="kind" class="dot-input" required>
                        @foreach (\App\Models\Engagement::KINDS as $value => $label)<option value="{{ $value }}" @selected(old('kind') === $value)>{{ $label }}</option>@endforeach
                    </select>
                </div>
            </div>
            <div class="rg-form-row rg-form-row--3">
                <div><label for="first_name">New contractor's first name</label><input id="first_name" name="first_name" class="dot-input" maxlength="80" value="{{ old('first_name') }}"></div>
                <div><label for="last_name">Last name</label><input id="last_name" name="last_name" class="dot-input" maxlength="80" value="{{ old('last_name') }}"></div>
                <div><label for="email">Email (optional)</label><input id="email" name="email" type="email" class="dot-input" maxlength="190" value="{{ old('email') }}"></div>
            </div>
            <div><label for="title">The work</label><input id="title" name="title" class="dot-input" maxlength="160" required value="{{ old('title') }}" placeholder="Electrical compliance audit, Plant B"></div>
            <div><label for="scope">Scope of work</label><textarea id="scope" name="scope" class="dot-input" rows="4" maxlength="5000" placeholder="What is delivered, where, and what is not included">{{ old('scope') }}</textarea></div>
            <div class="rg-form-row rg-form-row--3">
                <div><label for="rate_amount">Rate</label><input id="rate_amount" name="rate_amount" type="number" step="0.01" min="0" class="dot-input" value="{{ old('rate_amount') }}"></div>
                <div>
                    <label for="rate_basis">Paid</label>
                    <select id="rate_basis" name="rate_basis" class="dot-input" required>
                        @foreach (\App\Models\Engagement::RATE_BASES as $value => $label)<option value="{{ $value }}" @selected(old('rate_basis') === $value)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div><label for="currency">Currency</label><input id="currency" name="currency" class="dot-input" maxlength="3" value="{{ old('currency', 'ZAR') }}"></div>
            </div>
            <div class="rg-form-row">
                <div><label for="starts_on">Starts</label><input id="starts_on" name="starts_on" type="date" class="dot-input" required value="{{ old('starts_on', \App\Support\Hr\LocalTime::today()->toDateString()) }}"></div>
                <div><label for="ends_on">Ends (leave empty if open-ended)</label><input id="ends_on" name="ends_on" type="date" class="dot-input" value="{{ old('ends_on') }}"></div>
            </div>
            <p class="dot-note" style="margin:0;">{{ config('hr.classification_notice') }}</p>
            <div><button type="submit" class="dot-btn dot-btn-primary">Start the engagement</button></div>
        </form>
    </section>

    @if ($ended->isNotEmpty())
        <section class="rg-section">
            <h2>Ended</h2>
            <div class="dot-card dot-card--pad">
                @foreach ($ended as $engagement)
                    <a href="{{ route('contractors.show', $engagement) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                        <div style="min-width:0;"><h4>{{ $engagement->employee->fullName() }} — {{ $engagement->title }}</h4><div class="m">{{ $engagement->starts_on->format('j M Y') }} to {{ $engagement->ended_on?->format('j M Y') }} · {{ \App\Models\Engagement::END_REASONS[$engagement->end_reason] ?? '' }}</div></div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</div>
</x-app-layout>
