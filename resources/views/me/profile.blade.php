<x-app-layout>
<div class="page-pad" style="max-width:860px;">
    <h1>Your professional profile</h1>
    <p class="rg-sub">Write it once; it travels with you. Nothing here is filled in for you, and you decide who sees it.</p>

    <x-flash />
    @if ($errors->any())
        <div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>
    @endif

    {{-- The basics + who may see them --}}
    <form method="POST" action="{{ route('me.profile.update') }}" class="dot-card dot-card--pad">
        @csrf
        @method('PUT')

        <div style="display:grid;gap:14px;">
            <div>
                <label for="headline">Headline</label>
                <input id="headline" name="headline" class="dot-input" maxlength="140" value="{{ old('headline', $profile->headline) }}" placeholder="Bookkeeper · Sage and Pastel · six years in retail">
            </div>
            <div>
                <label for="about">About you</label>
                <textarea id="about" name="about" class="dot-input" rows="5" maxlength="3000" placeholder="What you do well, and the kind of work you want next.">{{ old('about', $profile->about) }}</textarea>
            </div>
            <div class="rg-form-row">
                <div>
                    <label for="location">Where you are</label>
                    <input id="location" name="location" class="dot-input" maxlength="120" value="{{ old('location', $profile->location) }}" placeholder="Mahikeng, North West">
                </div>
                <div>
                    <label for="availability">Looking for work?</label>
                    <select id="availability" name="availability" class="dot-input">
                        @foreach (\App\Models\ProfessionalProfile::AVAILABILITY as $value => $label)
                            <option value="{{ $value }}" @selected(old('availability', $profile->availability) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <fieldset style="border:0;padding:0;margin:0;">
                <legend style="font-size:13px;font-weight:500;margin-bottom:6px;">How you like to work</legend>
                <div class="rg-checks">
                    @foreach (\App\Models\ProfessionalProfile::ARRANGEMENTS as $value => $label)
                        <label><input type="checkbox" name="work_arrangements[]" value="{{ $value }}" @checked(in_array($value, old('work_arrangements', $profile->work_arrangements ?? []), true))> {{ $label }}</label>
                    @endforeach
                </div>
            </fieldset>
            <fieldset style="border:0;padding:0;margin:0;">
                <legend style="font-size:13px;font-weight:500;margin-bottom:6px;">Kinds of work you want <span class="dot-note">(up to 12)</span></legend>
                <div class="rg-checks">
                    @foreach (\App\Models\ProfessionalProfile::WORK_TYPES as $value => $label)
                        <label><input type="checkbox" name="work_types[]" value="{{ $value }}" @checked(in_array($value, old('work_types', $profile->work_types ?? []), true))> {{ $label }}</label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset id="privacy" style="border:0;padding:0;margin:0;">
                <legend style="font-size:13px;font-weight:500;margin-bottom:6px;">Who can see your profile</legend>
                <div style="display:grid;gap:6px;">
                    @foreach (\App\Models\ProfessionalProfile::VISIBILITY as $value => $label)
                        <label style="display:flex;gap:8px;align-items:flex-start;font-weight:400;">
                            <input type="radio" name="visibility" value="{{ $value }}" @checked(old('visibility', $profile->visibility) === $value) style="margin-top:3px;">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <p class="dot-note" style="margin:8px 0 0;">Your email address and phone number are never shown on your profile, whichever you choose.</p>
            </fieldset>

            <div><button type="submit" class="dot-btn dot-btn-primary">Save profile</button></div>
        </div>
    </form>

    {{-- Skills --}}
    <section class="rg-section" id="skills">
        <h2>Skills</h2>
        <div class="dot-card dot-card--pad">
            <div class="rg-chips" style="margin-bottom:{{ $profile->skills->isEmpty() ? '0' : '12px' }};">
                @foreach ($profile->skills as $skill)
                    <span class="rg-chip">
                        {{ $skill->name }}
                        <form method="POST" action="{{ route('me.skills.destroy', $skill->id) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" aria-label="Remove {{ $skill->name }}">×</button>
                        </form>
                    </span>
                @endforeach
            </div>
            <form method="POST" action="{{ route('me.skills.store') }}" style="display:flex;gap:8px;">
                @csrf
                <label for="skill-name" class="sr-only" style="position:absolute;left:-9999px;">Add a skill</label>
                <input id="skill-name" name="name" class="dot-input" maxlength="60" placeholder="Add a skill — e.g. Pastel Partner, payroll, Code 14 licence">
                <button type="submit" class="dot-btn">Add</button>
            </form>
        </div>
    </section>

    {{-- History: experience, education, certifications --}}
    @foreach (\App\Models\ProfileEntry::KINDS as $kind => $kindLabel)
        <section class="rg-section" id="{{ $kind }}">
            <h2>{{ $kindLabel }}</h2>
            <div class="dot-card dot-card--pad">
                @forelse ($profile->entriesOf($kind) as $entry)
                    <div class="rg-entry">
                        <div>
                            <h4>{{ $entry->title }}</h4>
                            <div class="m">
                                {{ $entry->organisation }}
                                @if ($entry->period()) @if ($entry->organisation) · @endif {{ $entry->period() }} @endif
                                @if ($entry->expires_on)
                                    · {{ $entry->hasLapsed() ? 'lapsed' : 'valid until' }} {{ $entry->expires_on->format('j M Y') }}
                                @endif
                            </div>
                            @if ($entry->description)<p class="dot-note" style="margin:6px 0 0;white-space:pre-line;">{{ $entry->description }}</p>@endif
                        </div>
                        <form method="POST" action="{{ route('me.entries.destroy', $entry->id) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="dot-btn dot-btn-danger" aria-label="Remove {{ $entry->title }}">Remove</button>
                        </form>
                    </div>
                @empty
                    <p class="dot-note" style="margin:0 0 6px;">Nothing here yet.</p>
                @endforelse

                <details style="margin-top:12px;">
                    <summary class="dot-btn dot-btn-ghost" style="list-style:none;">Add to {{ Str::lower($kindLabel) }}</summary>
                    <form method="POST" action="{{ route('me.entries.store') }}" style="display:grid;gap:10px;margin-top:10px;">
                        @csrf
                        <input type="hidden" name="kind" value="{{ $kind }}">
                        <div class="rg-form-row">
                            <div>
                                <label for="{{ $kind }}-title">{{ $kind === 'experience' ? 'Job title' : ($kind === 'education' ? 'Qualification' : 'Certificate or licence') }}</label>
                                <input id="{{ $kind }}-title" name="title" class="dot-input" required maxlength="140">
                            </div>
                            <div>
                                <label for="{{ $kind }}-org">{{ $kind === 'experience' ? 'Employer' : ($kind === 'education' ? 'Institution' : 'Issued by') }}</label>
                                <input id="{{ $kind }}-org" name="organisation" class="dot-input" maxlength="140">
                            </div>
                        </div>
                        <div class="rg-form-row">
                            <div>
                                <label for="{{ $kind }}-from">{{ $kind === 'certification' ? 'Issued' : 'From' }}</label>
                                <input id="{{ $kind }}-from" type="date" name="started_on" class="dot-input">
                            </div>
                            @if ($kind === 'certification')
                                <div>
                                    <label for="{{ $kind }}-exp">Expires (if it does)</label>
                                    <input id="{{ $kind }}-exp" type="date" name="expires_on" class="dot-input">
                                </div>
                            @else
                                <div>
                                    <label for="{{ $kind }}-to">To</label>
                                    <input id="{{ $kind }}-to" type="date" name="ended_on" class="dot-input">
                                    <label style="display:inline-flex;gap:6px;align-items:center;font-weight:400;margin-top:6px;"><input type="checkbox" name="is_current" value="1"> I'm still here</label>
                                </div>
                            @endif
                        </div>
                        @if ($kind === 'experience')
                            <div>
                                <label for="{{ $kind }}-desc">What you did</label>
                                <textarea id="{{ $kind }}-desc" name="description" class="dot-input" rows="3" maxlength="2000"></textarea>
                            </div>
                        @endif
                        <div><button type="submit" class="dot-btn dot-btn-primary">Add</button></div>
                    </form>
                </details>
            </div>
        </section>
    @endforeach

    <section class="rg-section">
        <h2>Your data</h2>
        <div class="dot-card dot-card--pad" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <p class="dot-note" style="margin:0;max-width:60ch;">Download your account, profile and every application you made, as one file. What a business holds about you as an employee is in that business's My work.</p>
            <a href="{{ route('me.data') }}" class="dot-btn">Download my data</a>
        </div>
    </section>

    {{-- Blocks --}}
    <section class="rg-section" id="blocks">
        <h2>Businesses you've blocked</h2>
        <div class="dot-card dot-card--pad">
            <p class="dot-note" style="margin:0 0 10px;">A blocked business never sees your profile, whatever your visibility setting.</p>
            @foreach ($profile->blockedTeams as $blocked)
                <div class="rg-entry">
                    <h4>{{ $blocked->name }}</h4>
                    <form method="POST" action="{{ route('me.blocks.destroy', $blocked->id) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="dot-btn dot-btn-ghost">Unblock</button>
                    </form>
                </div>
            @endforeach
            <form method="POST" action="{{ route('me.blocks.store') }}" style="display:flex;gap:8px;margin-top:10px;">
                @csrf
                <label for="block-name" style="position:absolute;left:-9999px;">Business name</label>
                <input id="block-name" name="business" class="dot-input" maxlength="255" placeholder="The business's name, exactly as it writes it">
                <button type="submit" class="dot-btn">Block</button>
            </form>
        </div>
    </section>
</div>
</x-app-layout>
