<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:640px;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 1.5rem;">New Leave Request</h1>

    <form method="POST" action="{{ route('leave-requests.store') }}" class="dot-card" style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf

        <div>
            <x-label for="employee_id" value="Employee" />
            <select id="employee_id" name="employee_id" class="dot-input" required>
                <option value="">— Select —</option>
                @foreach ($employees as $employee)
                <option value="{{ $employee->id }}" @selected(old('employee_id') == $employee->id)>{{ $employee->fullName() }}</option>
                @endforeach
            </select>
            <x-input-error for="employee_id" class="mt-2" />
        </div>

        <div>
            <x-label for="type" value="Type" />
            <select id="type" name="type" class="dot-input">
                @foreach(['annual' => 'Annual', 'sick' => 'Sick', 'unpaid' => 'Unpaid', 'other' => 'Other'] as $value => $label)
                <option value="{{ $value }}" @selected(old('type', 'annual') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <x-input-error for="type" class="mt-2" />
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <x-label for="start_date" value="Start date" />
                <input id="start_date" name="start_date" type="date" class="dot-input" value="{{ old('start_date') }}" required />
                <x-input-error for="start_date" class="mt-2" />
            </div>
            <div>
                <x-label for="end_date" value="End date" />
                <input id="end_date" name="end_date" type="date" class="dot-input" value="{{ old('end_date') }}" required />
                <x-input-error for="end_date" class="mt-2" />
            </div>
        </div>

        <div>
            <x-label for="reason" value="Reason (optional)" />
            <textarea id="reason" name="reason" class="dot-input" rows="3">{{ old('reason') }}</textarea>
            <p style="font-size:0.72rem;color:#52525b;margin-top:0.35rem;">Treated as sensitive — avoid including detailed medical information; a status of "Sick" is sufficient for most approvals.</p>
            <x-input-error for="reason" class="mt-2" />
        </div>

        <div style="display:flex;gap:0.75rem;margin-top:0.5rem;">
            <button type="submit" class="dot-btn dot-btn-primary">Submit Request</button>
            <a href="{{ route('leave-requests.index') }}" class="dot-btn dot-btn-ghost">Cancel</a>
        </div>
    </form>
</div>
</x-app-layout>
