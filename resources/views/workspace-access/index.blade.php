<x-app-layout>
<div class="page-pad">
    <h1>Who may recruit and supervise for {{ $team->name }}</h1>
    <p class="rg-sub" style="max-width:68ch;">
        InfoDot decides who belongs to {{ $team->name }}. Belonging is not enough to hire on its behalf:
        only the people you grant below may publish roles, handle candidates, build schedules or approve timesheets. HR administrators always may.
    </p>

    <x-flash />

    <div class="dot-card" style="overflow:hidden;">
        <div class="table-scroll">
            <table style="width:100%;">
                <thead>
                    <tr>
                        <th class="dot-th">Person</th>
                        <th class="dot-th">Role in the business</th>
                        <th class="dot-th">Granted</th>
                        <th class="dot-th" style="text-align:right;">Grant</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($members as $row)
                        @php $member = $row['user']; @endphp
                        <tr>
                            <td class="dot-td">{{ $member->name }}</td>
                            <td class="dot-td">{{ $row['role'] }}</td>
                            <td class="dot-td">
                                @if ($row['isAdmin'])
                                    <span class="dot-badge dot-badge-accent">Always — HR administrator</span>
                                @else
                                    @forelse ($row['grants'] as $grant)
                                        <span class="dot-badge dot-badge-ok" style="margin-right:4px;">
                                            {{ explode(' —', $capabilities[$grant->capability] ?? $grant->capability)[0] }}
                                        </span>
                                        <form method="POST" action="{{ route('access.destroy', $grant) }}" style="display:inline;">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="dot-btn dot-btn-danger" style="min-height:28px;padding:2px 8px;" aria-label="Remove {{ $grant->capability }} from {{ $member->name }}">Remove</button>
                                        </form>
                                    @empty
                                        <span class="dot-note">No</span>
                                    @endforelse
                                @endif
                            </td>
                            <td class="dot-td" style="text-align:right;">
                                @unless ($row['isAdmin'])
                                    @php $remaining = array_diff(array_keys($capabilities), $row['grants']->pluck('capability')->all()); @endphp
                                    @if ($remaining !== [])
                                        <form method="POST" action="{{ route('access.store') }}" style="display:inline-flex;gap:6px;justify-content:flex-end;">
                                            @csrf
                                            <input type="hidden" name="user_id" value="{{ $member->id }}">
                                            <label for="cap-{{ $member->id }}" style="position:absolute;left:-9999px;">Capability for {{ $member->name }}</label>
                                            <select id="cap-{{ $member->id }}" name="capability" class="dot-input" style="width:auto;min-height:32px;padding:4px 8px;">
                                                @foreach ($remaining as $capability)
                                                    <option value="{{ $capability }}">{{ explode(' —', $capabilities[$capability])[0] }}</option>
                                                @endforeach
                                            </select>
                                            <button type="submit" class="dot-btn" style="min-height:32px;">Grant</button>
                                        </form>
                                    @endif
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="rg-grid" style="margin-top:16px;">
        @foreach ($capabilities as $capability => $description)
            <div class="dot-card dot-card--pad s6">
                <div style="font-weight:500;">{{ explode(' —', $description)[0] }}</div>
                <p class="dot-note" style="margin:4px 0 0;">{{ ucfirst(\Illuminate\Support\Str::after($description, '— ')) }}</p>
            </div>
        @endforeach
    </div>

    <p class="dot-note" style="margin-top:18px;">
        Every grant and removal is written to the audit log.
        @if ($infodotBusinessUrl)
            {{ $team->name }}'s business details — logo, registration and verification — live on its
            <a href="{{ $infodotBusinessUrl }}" target="_blank" rel="noopener">InfoDot business page</a>; Dot.HR does not keep a second copy.
        @endif
    </p>
</div>
</x-app-layout>
