<x-app-layout>
<div class="page-pad" style="max-width:860px;">
    <h1>{{ $vacancy->exists ? 'Edit '.$vacancy->title : 'A new role' }}</h1>
    <p class="rg-sub">Say plainly what the work is, what it pays, and who would thrive in it. Saving keeps it a draft; nothing is public until you publish.</p>

    @if ($errors->any())
        <div class="dot-flash dot-flash-err" role="alert">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <form method="POST" action="{{ $vacancy->exists ? route('hiring.update', $vacancy) : route('hiring.store') }}" class="dot-card dot-card--pad" style="display:grid;gap:14px;">
        @csrf
        @if ($vacancy->exists) @method('PUT') @endif

        <div>
            <label for="title">Job title</label>
            <input id="title" name="title" class="dot-input" required maxlength="140" value="{{ old('title', $vacancy->title) }}" placeholder="Bookkeeper">
        </div>

        <div class="rg-form-row">
            <div>
                <label for="work_type">Kind of work</label>
                <select id="work_type" name="work_type" class="dot-input">
                    @foreach (\App\Models\ProfessionalProfile::WORK_TYPES as $value => $label)
                        <option value="{{ $value }}" @selected(old('work_type', $vacancy->work_type) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="arrangement">Where the work happens</label>
                <select id="arrangement" name="arrangement" class="dot-input">
                    @foreach (\App\Models\ProfessionalProfile::ARRANGEMENTS as $value => $label)
                        <option value="{{ $value }}" @selected(old('arrangement', $vacancy->arrangement) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="rg-form-row">
            <div>
                <label for="location">Location</label>
                <input id="location" name="location" class="dot-input" maxlength="120" value="{{ old('location', $vacancy->location) }}" placeholder="Mahikeng, North West">
            </div>
            <div>
                <label for="hours">Hours or shifts</label>
                <input id="hours" name="hours" class="dot-input" maxlength="120" value="{{ old('hours', $vacancy->hours) }}" placeholder="Mon–Fri, 08:00–17:00">
            </div>
        </div>

        <fieldset style="border:0;padding:0;margin:0;">
            <legend style="font-size:13px;font-weight:500;margin-bottom:6px;">Pay</legend>
            <div style="display:grid;grid-template-columns:repeat(4, minmax(0,1fr));gap:10px;" class="pay-row">
                <div>
                    <label for="pay_basis" class="dot-note">Basis</label>
                    <select id="pay_basis" name="pay_basis" class="dot-input">
                        @foreach (\App\Models\Vacancy::PAY_BASES as $value => $label)
                            <option value="{{ $value }}" @selected(old('pay_basis', $vacancy->pay_basis) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="pay_min" class="dot-note">From</label>
                    <input id="pay_min" name="pay_min" type="number" min="0" step="0.01" class="dot-input" value="{{ old('pay_min', $vacancy->pay_min) }}">
                </div>
                <div>
                    <label for="pay_max" class="dot-note">To (optional)</label>
                    <input id="pay_max" name="pay_max" type="number" min="0" step="0.01" class="dot-input" value="{{ old('pay_max', $vacancy->pay_max) }}">
                </div>
                <div>
                    <label for="currency" class="dot-note">Currency</label>
                    <input id="currency" name="currency" class="dot-input" maxlength="3" value="{{ old('currency', $vacancy->currency ?? 'ZAR') }}">
                </div>
            </div>
            <p class="dot-note" style="margin:6px 0 0;">Roles that show their pay get read. "Not disclosed" is allowed and shown honestly as such.</p>
        </fieldset>

        <div>
            <label for="description">About the role</label>
            <textarea id="description" name="description" class="dot-input" rows="6" required maxlength="8000">{{ old('description', $vacancy->description) }}</textarea>
        </div>
        <div class="rg-form-row">
            <div>
                <label for="responsibilities">What you'll do</label>
                <textarea id="responsibilities" name="responsibilities" class="dot-input" rows="5" maxlength="5000">{{ old('responsibilities', $vacancy->responsibilities) }}</textarea>
            </div>
            <div>
                <label for="requirements">What you'll need</label>
                <textarea id="requirements" name="requirements" class="dot-input" rows="5" maxlength="5000">{{ old('requirements', $vacancy->requirements) }}</textarea>
            </div>
        </div>
        <div>
            <label for="skills">Skills, comma-separated <span class="dot-note">(up to 15 — candidates see which of theirs match)</span></label>
            <input id="skills" name="skills" class="dot-input" maxlength="1000" value="{{ old('skills', implode(', ', $vacancy->skills ?? [])) }}" placeholder="Sage, Pastel Partner, payroll, VAT returns">
        </div>
        <div>
            <label for="screening_questions">Questions for applicants <span class="dot-note">(one per line, up to 5 — every applicant answers them)</span></label>
            <textarea id="screening_questions" name="screening_questions" class="dot-input" rows="3" maxlength="2000" placeholder="How many years have you worked with Pastel?">{{ old('screening_questions', implode("\n", $vacancy->screening_questions ?? [])) }}</textarea>
        </div>

        <div style="display:grid;grid-template-columns:repeat(3, minmax(0,1fr));gap:10px;" class="pay-row">
            <div>
                <label for="openings">Openings</label>
                <input id="openings" name="openings" type="number" min="1" max="500" class="dot-input" value="{{ old('openings', $vacancy->openings ?? 1) }}">
            </div>
            <div>
                <label for="starts_on">Start date</label>
                <input id="starts_on" name="starts_on" type="date" class="dot-input" value="{{ old('starts_on', $vacancy->starts_on?->format('Y-m-d')) }}">
            </div>
            <div>
                <label for="closes_on">Applications close</label>
                <input id="closes_on" name="closes_on" type="date" class="dot-input" value="{{ old('closes_on', $vacancy->closes_on?->format('Y-m-d')) }}">
            </div>
        </div>

        <div style="display:flex;gap:8px;">
            <button type="submit" class="dot-btn dot-btn-primary">{{ $vacancy->exists ? 'Save changes' : 'Save draft' }}</button>
            <a href="{{ $vacancy->exists ? route('hiring.show', $vacancy) : route('hiring.index') }}" class="dot-btn dot-btn-ghost">Cancel</a>
        </div>
    </form>
</div>
<style>@media (max-width: 700px) { .pay-row { grid-template-columns: minmax(0,1fr) minmax(0,1fr) !important; } }</style>
</x-app-layout>
