<x-app-layout>
<div class="page-pad" style="padding:2rem 2.5rem 3rem;">
    <div style="margin-bottom:1.5rem;">
        <h1 style="margin:0 0 0.2rem;">Audit log</h1>
        <p class="dot-note" style="margin:0;max-width:680px;">Who looked at, changed or removed HR records in this team. It names the fields that changed and keeps status and date values; it never holds a leave reason, email address or phone number. Entries cannot be edited or deleted.</p>
    </div>

    <form method="GET" action="{{ route('audit-log.index') }}" class="dot-card r-stack" style="padding:1rem 1.25rem;margin-bottom:1.5rem;display:grid;grid-template-columns:repeat(5,1fr) auto;gap:0.75rem;align-items:end;">
        <div>
            <label class="dot-label" for="action">Action</label>
            <select id="action" name="action" class="dot-input">
                <option value="">All</option>
                @foreach ($actions as $action)
                <option value="{{ $action }}" @selected(request('action') === $action)>{{ $action }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="dot-label" for="subject">Subject</label>
            <select id="subject" name="subject" class="dot-input">
                <option value="">All</option>
                @foreach (['employee' => 'Employee', 'leaverequest' => 'Leave request', 'position' => 'Position', 'leavetype' => 'Leave type'] as $value => $label)
                <option value="{{ $value }}" @selected(request('subject') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="dot-label" for="actor">Who</label>
            <select id="actor" name="actor" class="dot-input">
                <option value="">Anyone</option>
                @foreach ($actors as $actor)
                <option value="{{ $actor->id }}" @selected((string) request('actor') === (string) $actor->id)>{{ $actor->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="dot-label" for="from">From</label>
            <input id="from" type="date" name="from" value="{{ request('from') }}" class="dot-input" />
        </div>
        <div>
            <label class="dot-label" for="to">To</label>
            <input id="to" type="date" name="to" value="{{ request('to') }}" class="dot-input" />
        </div>
        <button type="submit" class="dot-btn dot-btn-primary">Filter</button>
    </form>

    <div class="dot-card table-scroll" style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
            <thead>
                <tr style="border-bottom:1px solid var(--rg-rule);">
                    <th class="dot-th">When</th>
                    <th class="dot-th">Who</th>
                    <th class="dot-th">Action</th>
                    <th class="dot-th">Subject</th>
                    <th class="dot-th">Fields</th>
                    <th class="dot-th">Detail</th>
                    <th class="dot-th">IP</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($entries as $entry)
                <tr data-audit-row data-action="{{ $entry->action }}" style="border-bottom:1px solid var(--rg-rule);vertical-align:top;">
                    <td class="dot-td dot-figure" style="color:var(--rg-muted);white-space:nowrap;">{{ $entry->created_at?->format('Y-m-d H:i:s') }}</td>
                    <td class="dot-td" style="color:var(--rg-ink);">{{ $entry->actor?->name ?? ($entry->actor_user_id ? 'User #'.$entry->actor_user_id : 'System') }}</td>
                    <td class="dot-td dot-figure" style="color:var(--rg-ink);">{{ $entry->action }}</td>
                    <td class="dot-td" style="color:var(--rg-muted);">{{ $entry->subject_type ? class_basename($entry->subject_type).' #'.$entry->subject_id : '—' }}</td>
                    <td class="dot-td" style="color:var(--rg-muted);">{{ $entry->fields ? implode(', ', $entry->fields) : '—' }}</td>
                    <td class="dot-td dot-figure" style="color:var(--rg-muted);">
                        @forelse ($entry->changes ?? [] as $key => $change)
                            <div>{{ $key }}: {{ is_array($change) ? (($change['from'] ?? '∅').' → '.($change['to'] ?? '∅')) : json_encode($change) }}</div>
                        @empty
                            —
                        @endforelse
                    </td>
                    <td class="dot-td dot-figure" style="color:#8a8a94;">{{ $entry->ip ?? '—' }}</td>
                </tr>
                @empty
                <tr><td colspan="7" class="dot-td" style="padding:1.5rem 1rem;color:#8a8a94;text-align:center;">No entries match.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top:1.25rem;">{{ $entries->links() }}</div>
</div>
</x-app-layout>
