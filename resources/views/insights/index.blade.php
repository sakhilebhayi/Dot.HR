<x-app-layout>
<div class="page-pad">
    @php
        $w = $workforce;
        $r = $recruitment;
        $exposed = collect($coverage)->filter(fn ($c) => count($c['holders']) <= 1)->count();
        $types = ['full_time' => 'full time', 'part_time' => 'part time', 'contractor' => 'contractors'];
        $leaveMax = max([1, ...array_values($leave['months'])]);
        $stageOrder = ['new', 'review', 'shortlisted', 'interview', 'offer', 'hired', 'rejected', 'withdrawn'];
        $stageMax = max([1, ...array_values($r['stages'])]);
        $fmt = fn (float $n) => rtrim(rtrim(number_format($n, 1), '0'), '.');
    @endphp

    <h1 class="rg-greet">
        <em>{{ $w['active'] }} {{ Str::plural('person', $w['active']) }}</em> work at {{ $team->name }}.
        @if ($exposed > 0) <em>{{ $exposed }} {{ Str::plural('skill', $exposed) }}</em> {{ $exposed === 1 ? 'rests' : 'rest' }} on one person or nobody. @endif
    </h1>
    <p class="rg-sub">Worked out from Dot.HR's own records — nothing estimated or filled in. Each figure says how it is calculated.</p>
    <p style="margin:0 0 18px;"><a href="{{ route('insights.export') }}" class="dot-btn">Download everything as CSV</a> <span class="dot-note" style="margin-left:6px;">One row per figure: section, item, measure, value, period. Opens in any spreadsheet.</span></p>

    {{-- Workforce --}}
    <section class="rg-section" style="margin-top:0;">
        <div class="rg-section-head"><h2>People</h2><a href="{{ route('insights.export', ['section' => 'people']) }}" class="dot-btn dot-btn-ghost" style="min-height:30px;padding:3px 10px;">CSV</a></div>
        <div class="rg-grid">
            <div class="dot-card dot-card--pad s3 rg-stat">
                <b>{{ $w['active'] }}</b><span>Working here now</span>
                <small>@foreach ($w['byType'] as $type => $n){{ $n }} {{ $types[$type] ?? str_replace('_', ' ', $type) }}@if (! $loop->last), @endif @endforeach</small>
            </div>
            <div class="dot-card dot-card--pad s3 rg-stat"><b>{{ $w['joiners'] }}</b><span>Joined in the last year</span><small>Start date in the last 12 months.</small></div>
            <div class="dot-card dot-card--pad s3 rg-stat"><b>{{ $w['leavers'] }}</b><span>Left in the last year</span><small>Records closed with an end date in the last 12 months.</small></div>
            <div class="dot-card dot-card--pad s3 rg-stat">
                <b>{{ $w['turnover'] === null ? '—' : $fmt($w['turnover']).'%' }}</b><span>Turnover</span>
                <small>Leavers ÷ the average of headcount now and a year ago ({{ $fmt($w['averageHeadcount']) }}).@if ($w['averageHeadcount'] < 10 && $w['turnover'] !== null) In a small team one leaver moves this a lot.@endif</small>
            </div>
        </div>
    </section>

    {{-- Skills coverage --}}
    <section class="rg-section">
        <div class="rg-section-head"><h2>Skills the business needs</h2><a href="{{ route('insights.export', ['section' => 'skills']) }}" class="dot-btn dot-btn-ghost" style="min-height:30px;padding:3px 10px;">CSV</a></div>
        <div class="dot-card dot-card--pad">
            @if ($coverage === [])
                <p class="dot-note" style="margin:0;">Nothing to measure yet: list skills on an open role, or add requirements to positions under Certifications.</p>
            @else
                @foreach ($coverage as $c)
                    <div class="rg-entry" style="grid-template-columns:minmax(0,1fr);">
                        <div style="min-width:0;">
                            <h4>{{ $c['skill'] }}
                                @if (count($c['holders']) === 0) <span class="dot-badge dot-badge-bad">nobody</span>
                                @elseif (count($c['holders']) === 1) <span class="dot-badge dot-badge-warn">one person</span>
                                @else <span class="dot-badge dot-badge-muted">{{ count($c['holders']) }} people</span>@endif
                            </h4>
                            <div class="m">Needed by {{ implode(', ', $c['needed_by']) }}</div>
                            <div style="margin-top:6px;font-size:13.5px;">
                                @forelse ($c['holders'] as $h)
                                    <span style="display:inline-block;margin-right:14px;">{{ $h['name'] }} <span class="dot-note">— {{ \App\Services\Intelligence\SkillsEvidence::SOURCES[$h['source']] }}</span></span>
                                @empty
                                    <span class="dot-note">Not on record for anyone. <a href="{{ route('training.index', ['for' => $c['skill']]) }}#book">Plan training</a> or recruit.</span>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endforeach
                <p class="dot-note" style="margin:10px 0 0;">Evidence is a person's own profile (only where they let you see it), a certificate on file that has not expired, or training recorded as completed. Fewest holders first.</p>
            @endif
        </div>
    </section>

    {{-- Recruitment --}}
    <section class="rg-section">
        <div class="rg-section-head"><h2>Hiring</h2><a href="{{ route('insights.export', ['section' => 'hiring']) }}" class="dot-btn dot-btn-ghost" style="min-height:30px;padding:3px 10px;">CSV</a></div>
        <div class="rg-grid">
            <div class="dot-card dot-card--pad s3 rg-stat"><b>{{ $r['open'] }}</b><span>Open roles</span><small>Published on the job board now.</small></div>
            <div class="dot-card dot-card--pad s3 rg-stat"><b>{{ $r['applications90'] }}</b><span>Applications, last 90 days</span><small>Every application received.</small></div>
            <div class="dot-card dot-card--pad s3 rg-stat"><b>{{ $r['hires'] }}</b><span>Hired in the last year</span><small>Applications that reached "Hired".</small></div>
            <div class="dot-card dot-card--pad s3 rg-stat">
                <b>{{ $r['medianDaysToHire'] === null ? '—' : $r['medianDaysToHire'] }}</b><span>Days to hire (median)</span>
                <small>{{ $r['medianDaysToHire'] === null ? 'No hires through Dot.HR in the last year.' : 'From application to hire, middle value of '.$r['hires'].'.' }}</small>
            </div>

            @if ($r['costs'] !== [])
                <div class="dot-card dot-card--pad s12">
                    <div class="dot-label">What hiring cost, last 12 months</div>
                    @foreach ($r['costs'] as $currency => $total)
                        <p style="margin:4px 0 0;">
                            <span class="dot-figure">{{ $currency }} {{ number_format($total, 2, '.', ' ') }}</span> recorded{{ isset($r['costPerHire'][$currency]) ? ', so '.$currency.' '.number_format($r['costPerHire'][$currency], 2, '.', ' ').' per hire across '.$r['hires'].' '.Str::plural('hire', $r['hires']) : ', with no hires yet to divide it by' }}.
                        </p>
                    @endforeach
                    <p class="dot-note" style="margin:6px 0 0;">Outside costs recorded on each role (advertising, agencies, assessments, travel). Staff time is not recorded, so the true cost is higher.</p>
                </div>
            @endif

            <div class="dot-card dot-card--pad s6">
                <div class="dot-label">Where last year's applications are now</div>
                @if ($r['stages'] === [])
                    <p class="dot-note" style="margin:6px 0 0;">No applications in the last year.</p>
                @else
                    <div class="rg-hbars" role="list">
                        @foreach ($stageOrder as $stage)
                            @php $n = $r['stages'][$stage] ?? 0; @endphp
                            <div class="rg-hbar" role="listitem" title="{{ \App\Models\Application::STAGES[$stage] }}: {{ $n }}">
                                <span>{{ \App\Models\Application::STAGES[$stage] }}</span>
                                <span class="t"><i style="width: {{ $n ? max(1, round($n / $stageMax * 100)) : 0 }}%;"></i></span>
                                <span class="n">{{ $n }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="dot-card s6" style="overflow:hidden;">
                <div class="table-scroll">
                    <table style="width:100%;">
                        <thead><tr><th class="dot-th">Role</th><th class="dot-th">Days live</th><th class="dot-th">Applied</th><th class="dot-th">Got further</th><th class="dot-th">Hired</th></tr></thead>
                        <tbody>
                            @forelse ($r['vacancies'] as $v)
                                <tr>
                                    <td class="dot-td"><a href="{{ route('hiring.show', $v['vacancy']) }}">{{ $v['vacancy']->title }}</a>@if ($v['vacancy']->status !== 'published') <span class="dot-note">· {{ strtolower(\App\Models\Vacancy::STATUSES[$v['vacancy']->status]) }}</span>@endif</td>
                                    <td class="dot-td dot-figure">{{ $v['days'] ?? '—' }}</td>
                                    <td class="dot-td dot-figure">{{ $v['applications'] }}</td>
                                    <td class="dot-td dot-figure">{{ $v['progressed'] }}</td>
                                    <td class="dot-td dot-figure">{{ $v['hired'] }}</td>
                                </tr>
                            @empty
                                <tr><td class="dot-td dot-note" colspan="5">No roles published in the last year.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="dot-note" style="margin:0;padding:8px 14px 12px;">"Got further" means shortlisted, interviewed, offered or hired.</p>
            </div>
        </div>
    </section>

    {{-- Leave --}}
    <section class="rg-section">
        <div class="rg-section-head"><h2>Leave taken</h2><a href="{{ route('insights.export', ['section' => 'leave']) }}" class="dot-btn dot-btn-ghost" style="min-height:30px;padding:3px 10px;">CSV</a></div>
        <div class="rg-grid">
            <div class="dot-card dot-card--pad s8">
                <div class="dot-label">Approved leave, working days per month</div>
                <div class="rg-cols" style="--n: {{ count($leave['months']) }};" role="list">
                    @foreach ($leave['months'] as $label => $days)
                        <div class="rg-col" role="listitem" title="{{ $label }}: {{ $fmt($days) }} working days">
                            <span class="v">{{ $fmt($days) }}</span>
                            <span class="bar {{ $days == 0 ? 'bar--zero' : '' }}" style="height: {{ $days ? max(2, round($days / $leaveMax * 100)) : 2 }}%;"></span>
                            <span class="k">{{ \Illuminate\Support\Str::before($label, ' ') }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="dot-card dot-card--pad s4">
                <div class="dot-label">By type, same six months</div>
                @forelse ($leave['byType'] as $type => $days)
                    <div class="rg-entry"><div><h4 style="font-size:13.5px;">{{ $type }}</h4></div><span class="dot-figure">{{ $fmt($days) }} days</span></div>
                @empty
                    <p class="dot-note" style="margin:6px 0 0;">No approved leave in the last six months.</p>
                @endforelse
                <p class="dot-note" style="margin:10px 0 0;">Weekends and the public holidays you configured are not counted.</p>
            </div>
        </div>
    </section>

    {{-- Development and contractors --}}
    <section class="rg-section">
        <div class="rg-section-head"><h2>Training, certificates and contractors</h2><a href="{{ route('insights.export', ['section' => 'development']) }}" class="dot-btn dot-btn-ghost" style="min-height:30px;padding:3px 10px;">CSV</a></div>
        <div class="rg-grid">
            <div class="dot-card dot-card--pad s3 rg-stat"><b>{{ $development['completed'] }}</b><span>Trainings completed</span><small>Last 12 months{{ $development['hours'] ? ', '.$fmt($development['hours']).' hours recorded' : '' }}. {{ $development['planned'] }} booked, {{ $development['requested'] }} asked for.</small></div>
            <div class="dot-card dot-card--pad s3 rg-stat"><b>{{ $development['expired'] }}</b><span>Certificates expired</span><small>{{ $development['lapsing'] }} more lapse within {{ \App\Models\EmployeeCertification::SOON_DAYS }} days.</small></div>
            <div class="dot-card dot-card--pad s3 rg-stat"><b>{{ $contractors['active'] }}</b><span>Contractor engagements running</span><small>{{ $contractors['disputed'] ? $contractors['disputed'].' invoice'.($contractors['disputed'] === 1 ? '' : 's').' disputed.' : 'No invoices disputed.' }}</small></div>
            <div class="dot-card dot-card--pad s3 rg-stat">
                <b>@forelse ($contractors['spend'] as $currency => $amount){{ $currency }} {{ number_format($amount, 0) }}@if (! $loop->last)<br>@endif @empty — @endforelse</b>
                <span>Contractor invoices approved</span><small>Approved or paid, received in the last 12 months. Recorded here; paid elsewhere.</small>
            </div>
        </div>
    </section>
</div>
</x-app-layout>
