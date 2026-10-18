<x-app-layout>
<div class="page-pad" style="padding:2rem 2.5rem 3rem;max-width:640px;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 1.5rem;">Edit position</h1>

    <form method="POST" action="{{ route('positions.update', $position) }}" class="dot-card" style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf
        @method('PUT')
        <input type="hidden" name="assignments" value="1" />
        @include('positions._fields', ['position' => $position])

        <fieldset style="border:0;padding:0;margin:0;">
            <legend class="dot-label">People in this position</legend>
            @forelse ($employees as $employee)
            <label style="display:flex;align-items:center;gap:0.6rem;padding:0.4rem 0;border-top:1px solid rgba(255,255,255,0.06);font-size:0.85rem;color:#e4e4e7;cursor:pointer;">
                <input type="checkbox" name="employee_ids[]" value="{{ $employee->id }}" @checked(in_array($employee->id, old('employee_ids', $employees->where('position_id', $position->id)->pluck('id')->all()))) />
                <span>{{ $employee->fullName() }}</span>
                @if ($employee->position && $employee->position_id !== $position->id)
                    <span class="dot-note">currently {{ $employee->position->title }}</span>
                @endif
            </label>
            @empty
            <p class="dot-note" style="margin:0;">No employees yet.</p>
            @endforelse
            <x-input-error for="employee_ids.0" class="mt-2" />
            <p class="dot-note" style="margin:0.5rem 0 0;">Ticking someone moves them here from their current position. Unticking removes them from this one. Each change is logged.</p>
        </fieldset>

        <div style="display:flex;gap:0.75rem;margin-top:0.5rem;">
            <button type="submit" class="dot-btn dot-btn-primary">Save changes</button>
            <a href="{{ route('positions.index') }}" class="dot-btn dot-btn-ghost">Cancel</a>
        </div>
    </form>

    <form method="POST" action="{{ route('positions.destroy', $position) }}" style="margin-top:1.5rem;" onsubmit="return confirm('Delete this position? People in it become unassigned.');">
        @csrf
        @method('DELETE')
        <button type="submit" class="dot-btn dot-btn-danger">Delete position</button>
    </form>
</div>
</x-app-layout>
