<div>
    <x-label for="name" value="Name" />
    <input id="name" name="name" type="text" class="dot-input" value="{{ old('name', $type?->name) }}" required autofocus />
    <x-input-error for="name" class="mt-2" />
</div>
<div class="r-stack" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
    <div>
        <x-label for="annual_days" value="Working days a year" />
        <input id="annual_days" name="annual_days" type="number" step="0.5" min="0" max="366" class="dot-input" value="{{ old('annual_days', $type?->annual_days) }}" />
        <p class="dot-note" style="margin-top:0.35rem;">Leave empty to not count this type against a balance.</p>
        <x-input-error for="annual_days" class="mt-2" />
    </div>
    <div>
        <x-label for="carry_over_days" value="Carry over, at most" />
        <input id="carry_over_days" name="carry_over_days" type="number" step="0.5" min="0" max="366" class="dot-input" value="{{ old('carry_over_days', $type?->carry_over_days ?? 0) }}" />
        <p class="dot-note" style="margin-top:0.35rem;">Never more than what was left unused last year.</p>
        <x-input-error for="carry_over_days" class="mt-2" />
    </div>
</div>
<label style="display:flex;align-items:center;gap:0.6rem;font-size:0.85rem;color:#e4e4e7;">
    <input type="checkbox" name="is_paid" value="1" @checked(old('is_paid', $type?->is_paid ?? true)) /> Paid leave
</label>
<label style="display:flex;align-items:flex-start;gap:0.6rem;font-size:0.85rem;color:#e4e4e7;">
    <input type="checkbox" name="enforce_balance" value="1" style="margin-top:0.2rem;" @checked(old('enforce_balance', $type?->enforce_balance ?? false)) />
    <span>Refuse a request that would go beyond the balance
        <span class="dot-note" style="display:block;">If unticked, people can request more than they have and the balance runs negative. Only applies when a number of days a year is set.</span>
    </span>
</label>
