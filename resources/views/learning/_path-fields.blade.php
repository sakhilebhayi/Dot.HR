<div class="rg-form-row">
    <div><label for="lp-name">Name</label><input id="lp-name" name="name" class="dot-input" maxlength="120" required value="{{ old('name', $path?->name) }}" placeholder="A new cashier’s first month"></div>
    <div>
        <label for="lp-position">For a position (optional)</label>
        <select id="lp-position" name="position_id" class="dot-input">
            <option value="">Any position</option>
            @foreach ($positions as $position)<option value="{{ $position->id }}" @selected((int) old('position_id', $path?->position_id) === $position->id)>{{ $position->title }}</option>@endforeach
        </select>
    </div>
</div>
<div><label for="lp-desc">What it is for (optional)</label><textarea id="lp-desc" name="description" class="dot-input" rows="2" maxlength="2000" placeholder="Everything a cashier needs to work a till alone and close up safely.">{{ old('description', $path?->description) }}</textarea></div>
<label style="display:flex;gap:8px;align-items:flex-start;font-weight:400;">
    <input type="hidden" name="self_enrol" value="0">
    <input type="checkbox" name="self_enrol" value="1" @checked(old('self_enrol', $path?->self_enrol)) style="margin-top:4px;">
    <span>Anyone here may start it themselves from My work</span>
</label>
