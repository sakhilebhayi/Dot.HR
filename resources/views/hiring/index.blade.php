<x-app-layout>
<div class="page-pad">
    @php
        $open = $vacancies->where('status', 'published');
        $newCount = $vacancies->sum('new_applications_count');
    @endphp
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;">
        <div>
            <h1 class="rg-greet">
                @if ($open->isEmpty())
                    No roles are open right now.
                @else
                    <em>{{ $open->count() }} {{ Str::plural('role', $open->count()) }}</em> open{{ $newCount > 0 ? ',' : '.' }}
                    @if ($newCount > 0) <em>{{ $newCount }}</em> new {{ Str::plural('application', $newCount) }} to read. @endif
                @endif
            </h1>
            <p class="rg-sub" style="margin-bottom:0;">Hiring for {{ auth()->user()->currentTeam->name }}.</p>
        </div>
        @if ($canRecruit)
            <a href="{{ route('hiring.create') }}" class="dot-btn dot-btn-primary">New role</a>
        @endif
    </div>

    <div style="margin-top:22px;">
        <x-flash />
    </div>

    @if ($vacancies->isEmpty())
        <div class="dot-card dot-card--pad" style="max-width:620px;">
            <div style="font-weight:500;">Nothing here yet</div>
            <p class="dot-note" style="margin:4px 0 0;">
                @if ($canRecruit)
                    Write your first role. It stays a draft until you publish it to the Dot.HR job board.
                @else
                    When a recruiter in your business opens a role, its candidates appear here for you to review.
                @endif
            </p>
        </div>
    @else
        <div class="dot-card" style="overflow:hidden;">
            <div class="table-scroll">
                <table style="width:100%;">
                    <thead>
                        <tr>
                            <th class="dot-th">Role</th>
                            <th class="dot-th">Status</th>
                            <th class="dot-th" style="text-align:right;">New</th>
                            <th class="dot-th" style="text-align:right;">In play</th>
                            <th class="dot-th" style="text-align:right;">All</th>
                            <th class="dot-th">Closes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($vacancies as $vacancy)
                            <tr>
                                <td class="dot-td">
                                    <a href="{{ route('hiring.show', $vacancy) }}" style="font-weight:500;text-decoration:none;">{{ $vacancy->title }}</a>
                                    <div class="dot-note">{{ $vacancy->workTypeLabel() }} · {{ $vacancy->arrangementLabel() }}@if ($vacancy->location) · {{ $vacancy->location }}@endif</div>
                                </td>
                                <td class="dot-td">
                                    <span class="dot-badge {{ ['published' => 'dot-badge-ok', 'draft' => 'dot-badge-muted', 'paused' => 'dot-badge-warn', 'closed' => 'dot-badge-muted'][$vacancy->status] }}">{{ \App\Models\Vacancy::STATUSES[$vacancy->status] }}</span>
                                </td>
                                <td class="dot-td dot-figure" style="text-align:right;">{{ $vacancy->new_applications_count }}</td>
                                <td class="dot-td dot-figure" style="text-align:right;">{{ $vacancy->open_applications_count }}</td>
                                <td class="dot-td dot-figure" style="text-align:right;">{{ $vacancy->applications_count }}</td>
                                <td class="dot-td dot-note">{{ $vacancy->closes_on?->format('j M Y') ?? 'No date' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
</x-app-layout>
