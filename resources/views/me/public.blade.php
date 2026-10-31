<x-app-layout>
<div class="page-pad" style="max-width:820px;">
    {{-- The profile as others see it. Deliberately no email, phone or address: the
         profile never carries them (ProfessionalProfilePolicy). --}}
    <h1 class="rg-greet" style="margin-bottom:4px;">{{ $profile->user->name }}</h1>
    @if ($profile->headline)
        <p style="font-size:16px;margin:0;">{{ $profile->headline }}</p>
    @endif
    <p class="rg-sub">
        {{ \App\Models\ProfessionalProfile::AVAILABILITY[$profile->availability] ?? '' }}
        @if ($profile->location) · {{ $profile->location }} @endif
        @if ($profile->work_arrangements)
            · {{ collect($profile->work_arrangements)->map(fn ($a) => \App\Models\ProfessionalProfile::ARRANGEMENTS[$a] ?? null)->filter()->implode(', ') }}
        @endif
    </p>

    @if (auth()->id() === $profile->user_id)
        <div class="dot-flash" role="note">This is how others see your profile ({{ Str::lower(\App\Models\ProfessionalProfile::VISIBILITY[$profile->visibility]) }}). <a href="{{ route('me.profile') }}">Edit it</a>.</div>
    @endif

    @if ($profile->about)
        <div class="dot-card dot-card--pad" style="white-space:pre-line;">{{ $profile->about }}</div>
    @endif

    @if ($profile->skills->isNotEmpty())
        <section class="rg-section">
            <h2>Skills</h2>
            <div class="rg-chips">
                @foreach ($profile->skills as $skill)
                    <span class="rg-chip" style="padding-right:11px;">{{ $skill->name }}</span>
                @endforeach
            </div>
        </section>
    @endif

    @foreach (\App\Models\ProfileEntry::KINDS as $kind => $kindLabel)
        @php $items = $profile->entriesOf($kind); @endphp
        @if ($items->isNotEmpty())
            <section class="rg-section">
                <h2>{{ $kindLabel }}</h2>
                <div class="dot-card dot-card--pad">
                    @foreach ($items as $entry)
                        <div class="rg-entry">
                            <div>
                                <h4>{{ $entry->title }}</h4>
                                <div class="m">
                                    {{ $entry->organisation }}
                                    @if ($entry->period()) @if ($entry->organisation) · @endif {{ $entry->period() }} @endif
                                    @if ($entry->expires_on) · {{ $entry->hasLapsed() ? 'lapsed' : 'valid until' }} {{ $entry->expires_on->format('j M Y') }} @endif
                                </div>
                                @if ($entry->description)<p class="dot-note" style="margin:6px 0 0;white-space:pre-line;">{{ $entry->description }}</p>@endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    @endforeach

    @if ($profile->work_types)
        <section class="rg-section">
            <h2>Open to</h2>
            <p style="margin:0;">{{ collect($profile->work_types)->map(fn ($t) => \App\Models\ProfessionalProfile::WORK_TYPES[$t] ?? null)->filter()->implode(' · ') }}</p>
        </section>
    @endif
</div>
</x-app-layout>
