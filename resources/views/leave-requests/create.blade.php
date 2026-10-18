<x-app-layout>
<div class="page-pad" style="padding:2rem 2.5rem 3rem;max-width:640px;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 1.5rem;">{{ $isAdmin ? 'Record leave' : 'Request leave' }}</h1>

    @if ($snapshots->isNotEmpty())
    <div class="dot-card" style="padding:1rem 1.25rem;margin-bottom:1.25rem;">
        <div class="dot-label">Your balance</div>
        @foreach ($leaveTypes->filter->isTracked() as $type)
            @php $snap = $snapshots[$type->id]; @endphp
            <div style="display:flex;justify-content:space-between;gap:1rem;padding:0.4rem 0;{{ ! $loop->first ? 'border-top:1px solid rgba(255,255,255,0.06);' : '' }}">
                <span style="font-size:0.85rem;color:#e4e4e7;">{{ $type->name }}</span>
                <span class="dot-figure" style="font-size:0.85rem;color:#f4f4f5;">
                    {{ rtrim(rtrim(number_format($snap->remaining, 1), '0'), '.') ?: '0' }} <span style="color:#8a8a94;">of {{ rtrim(rtrim(number_format($snap->entitlement, 1), '0'), '.') }} days left</span>
                    @if ($snap->pending > 0)<span style="color:#fbbf24;"> &middot; {{ rtrim(rtrim(number_format($snap->pending, 1), '0'), '.') }} pending</span>@endif
                </span>
            </div>
        @endforeach
        <p class="dot-note" style="margin:0.6rem 0 0;">Working days in the leave year that began {{ $snapshots->first()->yearStart->format('j M Y') }}. {{ $leaveTypes->contains(fn ($t) => $t->isTracked() && $t->enforce_balance) ? 'A request beyond the balance is refused for types that enforce it.' : '' }}</p>
    </div>
    @endif

    <form method="POST" action="{{ route('leave-requests.store') }}" class="dot-card" style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf

        @if ($isAdmin)
        <div>
            <x-label for="employee_id" value="Employee" />
            <select id="employee_id" name="employee_id" class="dot-input" required>
                <option value="">— Select —</option>
                @foreach ($employees as $employee)
                <option value="{{ $employee->id }}" @selected(old('employee_id', $mine?->id) == $employee->id)>{{ $employee->fullName() }}</option>
                @endforeach
            </select>
            <p class="dot-note" style="margin-top:0.35rem;">Recording leave for someone else is logged. A request you file cannot be decided by you.</p>
            <x-input-error for="employee_id" class="mt-2" />
        </div>
        @endif

        <div>
            <x-label for="leave_type_id" value="Type" />
            <select id="leave_type_id" name="leave_type_id" class="dot-input" required>
                @foreach ($leaveTypes as $type)
                <option value="{{ $type->id }}" @selected(old('leave_type_id', $leaveTypes->firstWhere('slug', 'annual')?->id) == $type->id)>{{ $type->name }}</option>
                @endforeach
            </select>
            <x-input-error for="leave_type_id" class="mt-2" />
        </div>

        <div class="r-stack" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <x-label for="start_date" value="First day" />
                <input id="start_date" name="start_date" type="date" class="dot-input" value="{{ old('start_date') }}" required />
                <x-input-error for="start_date" class="mt-2" />
            </div>
            <div>
                <x-label for="end_date" value="Last day" />
                <input id="end_date" name="end_date" type="date" class="dot-input" value="{{ old('end_date') }}" required />
                <x-input-error for="end_date" class="mt-2" />
            </div>
        </div>

        <div>
            <x-label for="half_day" value="Half day (optional)" />
            <select id="half_day" name="half_day" class="dot-input">
                <option value="">Full days</option>
                <option value="am" @selected(old('half_day') === 'am')>Morning only</option>
                <option value="pm" @selected(old('half_day') === 'pm')>Afternoon only</option>
            </select>
            <p class="dot-note" style="margin-top:0.35rem;">Only for a single day: set both dates to that day.</p>
            <x-input-error for="half_day" class="mt-2" />
        </div>

        <div>
            <x-label for="reason" value="Reason (optional)" />
            <textarea id="reason" name="reason" class="dot-input" rows="3">{{ old('reason') }}</textarea>
            <p class="dot-note" style="margin-top:0.35rem;">Stored encrypted and shown only to HR administrators and you. Leave it empty if you can: the type is enough for most requests, so please do not enter medical detail.</p>
            <x-input-error for="reason" class="mt-2" />
        </div>

        <div style="display:flex;gap:0.75rem;margin-top:0.5rem;">
            <button type="submit" class="dot-btn dot-btn-primary">Submit request</button>
            <a href="{{ route('leave-requests.index') }}" class="dot-btn dot-btn-ghost">Cancel</a>
        </div>
    </form>
</div>
</x-app-layout>
