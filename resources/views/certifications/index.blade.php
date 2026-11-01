<x-app-layout>
<div class="page-pad">
    @php
        $expiredCount = $gaps->sum(fn ($g) => count($g['expired']));
        $missingCount = $gaps->sum(fn ($g) => count($g['missing']));
    @endphp
    <h1 class="rg-greet">
        @if ($requirements->isEmpty())
            No position has requirements yet.
        @elseif ($gaps->isEmpty())
            <em>Everyone</em> holds what their position requires.
        @else
            <em>{{ $gaps->count() }} {{ Str::plural('person', $gaps->count()) }}</em> {{ $gaps->count() === 1 ? 'is' : 'are' }} not fully ready{{ $expiredCount ? ' — '.$expiredCount.' expired' : '' }}{{ $missingCount ? ($expiredCount ? ', ' : ' — ').$missingCount.' missing' : '' }}.
        @endif
    </h1>
    <p class="rg-sub">Licences, medicals, inductions and courses — what each position requires, who holds what, and what lapses within {{ \App\Models\EmployeeCertification::SOON_DAYS }} days.</p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    @if ($gaps->isNotEmpty())
        <div class="dot-card dot-card--pad" style="margin-bottom:14px;">
            <div class="dot-label">Not ready</div>
            @foreach ($gaps as $gap)
                <div class="rg-entry" style="align-items:flex-start;">
                    <div>
                        <h4><a href="{{ route('employees.show', $gap['employee']) }}" style="text-decoration:none;">{{ $gap['employee']->fullName() }}</a></h4>
                        <div class="rg-chips" style="margin-top:6px;">
                            @foreach ($gap['expired'] as $name)<span class="dot-badge dot-badge-bad">{{ $name }} — expired</span>@endforeach
                            @foreach ($gap['missing'] as $name)<span class="dot-badge dot-badge-warn">{{ $name }} — missing</span>@endforeach
                            @foreach ($gap['soon'] as $name)<span class="dot-badge dot-badge-muted">{{ $name }} — lapses soon</span>@endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($expiring->isNotEmpty())
        <div class="dot-card dot-card--pad" style="margin-bottom:14px;">
            <div class="dot-label">Expired or lapsing within {{ \App\Models\EmployeeCertification::SOON_DAYS }} days</div>
            @foreach ($expiring as $cert)
                <div class="rg-entry"><div><h4>{{ $cert->employee?->fullName() }} — {{ $cert->name }}</h4></div><span class="dot-badge {{ $cert->state() === 'expired' ? 'dot-badge-bad' : 'dot-badge-warn' }}">{{ $cert->state() === 'expired' ? 'expired' : 'expires' }} {{ $cert->expires_on->format('j M Y') }}</span></div>
            @endforeach
        </div>
    @endif

    <section class="rg-section">
        <h2>What each position requires</h2>
        <div class="dot-card dot-card--pad">
            @forelse ($positions as $position)
                <div class="rg-entry rg-entry--tools" style="align-items:flex-start;">
                    <div style="min-width:0;">
                        <h4>{{ $position->title }}</h4>
                        <div class="rg-chips" style="margin-top:6px;">
                            @forelse ($requirements->get($position->id, collect()) as $requirement)
                                <span class="rg-chip" @unless ($isAdmin) style="padding-right:11px;" @endunless>
                                    {{ $requirement->name }}
                                    @if ($isAdmin)
                                        <form method="POST" action="{{ route('requirements.destroy', $requirement) }}">@csrf @method('DELETE')<button type="submit" aria-label="Remove {{ $requirement->name }}">×</button></form>
                                    @endif
                                </span>
                            @empty
                                <span class="dot-note">Nothing required.</span>
                            @endforelse
                        </div>
                    </div>
                    @if ($isAdmin)
                        <form method="POST" action="{{ route('requirements.store', $position) }}" style="display:flex;gap:6px;margin:0;">
                            @csrf
                            <label for="req-{{ $position->id }}" style="position:absolute;left:-9999px;">Requirement for {{ $position->title }}</label>
                            <input id="req-{{ $position->id }}" name="name" class="dot-input" maxlength="140" placeholder="Code 14 licence" style="width:180px;min-height:32px;padding:4px 8px;">
                            <button type="submit" class="dot-btn" style="min-height:32px;">Require</button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="dot-note" style="margin:0;">Add positions first; then say what each one requires.</p>
            @endforelse
        </div>
    </section>

    <section class="rg-section">
        <h2>Everything on file</h2>
        <div class="dot-card" style="overflow:hidden;">
            <div class="table-scroll">
                <table style="width:100%;">
                    <thead><tr><th class="dot-th">Person</th><th class="dot-th">Certificate</th><th class="dot-th">Issued by</th><th class="dot-th">Expires</th><th class="dot-th">Verified</th><th class="dot-th"></th></tr></thead>
                    <tbody>
                        @forelse ($all as $cert)
                            <tr>
                                <td class="dot-td">{{ $cert->employee?->fullName() }}</td>
                                <td class="dot-td">{{ $cert->name }}</td>
                                <td class="dot-td dot-note">{{ $cert->issuer ?? '—' }}</td>
                                <td class="dot-td">
                                    @if ($cert->expires_on)
                                        <span class="dot-badge {{ ['expired' => 'dot-badge-bad', 'soon' => 'dot-badge-warn', 'valid' => 'dot-badge-muted'][$cert->state()] }}">{{ $cert->expires_on->format('j M Y') }}</span>
                                    @else
                                        <span class="dot-note">Does not expire</span>
                                    @endif
                                </td>
                                <td class="dot-td dot-note">
                                    @if ($cert->verified_at) {{ $cert->verifier?->name ?? 'Yes' }} · {{ $cert->verified_at->format('j M Y') }}
                                    @elseif ($isAdmin)
                                        <form method="POST" action="{{ route('certifications.verify', $cert) }}" style="margin:0;">@csrf<button type="submit" class="dot-btn dot-btn-ghost" style="min-height:28px;padding:2px 8px;">I've seen the original</button></form>
                                    @else Not yet @endif
                                </td>
                                <td class="dot-td">
                                    @if ($isAdmin)
                                        <form method="POST" action="{{ route('certifications.destroy', $cert) }}" style="margin:0;" data-confirm="Remove this record?">@csrf @method('DELETE')<button type="submit" class="dot-btn dot-btn-danger" style="min-height:28px;padding:2px 8px;">Remove</button></form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td class="dot-td dot-note" colspan="6">No certificates on file yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    @if ($isAdmin)
        <section class="rg-section">
            <h2>Record a certificate</h2>
            <form method="POST" action="{{ route('certifications.store') }}" class="dot-card dot-card--pad" style="display:grid;gap:10px;">
                @csrf
                <div class="rg-form-row">
                    <div>
                        <label for="cert-employee">Person</label>
                        <select id="cert-employee" name="employee_id" class="dot-input" required>
                            @foreach ($employees as $person)<option value="{{ $person->id }}">{{ $person->first_name }} {{ $person->last_name }}</option>@endforeach
                        </select>
                    </div>
                    <div><label for="cert-name">Certificate</label><input id="cert-name" name="name" class="dot-input" maxlength="140" required placeholder="Use the same name as the requirement"></div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;" class="pay-row">
                    <div><label for="cert-issuer">Issued by</label><input id="cert-issuer" name="issuer" class="dot-input" maxlength="140"></div>
                    <div><label for="cert-issued">Issued</label><input id="cert-issued" name="issued_on" type="date" class="dot-input"></div>
                    <div><label for="cert-expires">Expires</label><input id="cert-expires" name="expires_on" type="date" class="dot-input"></div>
                </div>
                <label style="display:inline-flex;gap:6px;align-items:center;font-weight:400;"><input type="checkbox" name="verified" value="1"> I have seen the original</label>
                <div><button type="submit" class="dot-btn dot-btn-primary">Record</button></div>
            </form>
        </section>
    @endif
</div>
<style>@media (max-width: 700px) { .pay-row { grid-template-columns: minmax(0,1fr) !important; } }</style>
</x-app-layout>
