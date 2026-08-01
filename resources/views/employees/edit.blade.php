<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:640px;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 1.5rem;">Edit Employee</h1>

    <form method="POST" action="{{ route('employees.update', $employee) }}" class="dot-card" style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf
        @method('PUT')
        @include('employees._form', ['employee' => $employee])
        <div style="display:flex;gap:0.75rem;margin-top:0.5rem;">
            <button type="submit" class="dot-btn dot-btn-primary">Save Changes</button>
            <a href="{{ route('employees.show', $employee) }}" class="dot-btn dot-btn-ghost">Cancel</a>
        </div>
    </form>
</div>
</x-app-layout>
