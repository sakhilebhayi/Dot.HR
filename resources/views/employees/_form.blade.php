@php $e = $employee; @endphp

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
    <div>
        <x-label for="first_name" value="First name" />
        <input id="first_name" name="first_name" type="text" class="dot-input" value="{{ old('first_name', $e?->first_name) }}" required autofocus />
        <x-input-error for="first_name" class="mt-2" />
    </div>
    <div>
        <x-label for="last_name" value="Last name" />
        <input id="last_name" name="last_name" type="text" class="dot-input" value="{{ old('last_name', $e?->last_name) }}" required />
        <x-input-error for="last_name" class="mt-2" />
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
    <div>
        <x-label for="email" value="Work email" />
        <input id="email" name="email" type="email" class="dot-input" value="{{ old('email', $e?->email) }}" />
        <x-input-error for="email" class="mt-2" />
    </div>
    <div>
        <x-label for="phone" value="Phone" />
        <input id="phone" name="phone" type="text" class="dot-input" value="{{ old('phone', $e?->phone) }}" />
        <x-input-error for="phone" class="mt-2" />
    </div>
</div>

<div>
    <x-label for="position_id" value="Position" />
    <select id="position_id" name="position_id" class="dot-input">
        <option value="">— None —</option>
        @foreach ($positions as $position)
        <option value="{{ $position->id }}" @selected(old('position_id', $e?->position_id) == $position->id)>{{ $position->title }}</option>
        @endforeach
    </select>
    <x-input-error for="position_id" class="mt-2" />
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
    <div>
        <x-label for="employment_type" value="Employment type" />
        <select id="employment_type" name="employment_type" class="dot-input">
            @foreach(['full_time' => 'Full time', 'part_time' => 'Part time', 'contractor' => 'Contractor'] as $value => $label)
            <option value="{{ $value }}" @selected(old('employment_type', $e?->employment_type ?? 'full_time') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <x-input-error for="employment_type" class="mt-2" />
    </div>
    <div>
        <x-label for="status" value="Status" />
        <select id="status" name="status" class="dot-input">
            @foreach(['active' => 'Active', 'on_leave' => 'On leave', 'terminated' => 'Terminated'] as $value => $label)
            <option value="{{ $value }}" @selected(old('status', $e?->status ?? 'active') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <x-input-error for="status" class="mt-2" />
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
    <div>
        <x-label for="start_date" value="Start date" />
        <input id="start_date" name="start_date" type="date" class="dot-input" value="{{ old('start_date', optional($e?->start_date)->format('Y-m-d')) }}" />
        <x-input-error for="start_date" class="mt-2" />
    </div>
    <div>
        <x-label for="end_date" value="End date" />
        <input id="end_date" name="end_date" type="date" class="dot-input" value="{{ old('end_date', optional($e?->end_date)->format('Y-m-d')) }}" />
        <x-input-error for="end_date" class="mt-2" />
    </div>
</div>
