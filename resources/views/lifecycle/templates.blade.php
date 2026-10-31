<x-app-layout>
<div class="page-pad" style="max-width:860px;">
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('lifecycle.index') }}">Lifecycle</a></p>
    <h1>Your checklists</h1>
    <p class="rg-sub">The steps every new joiner and every leaver gets in {{ auth()->user()->currentTeam->name }}. One step per line. These are your business's working steps — not legal advice; adjust them to your contracts and your jurisdiction.</p>

    <x-flash />

    @foreach (['onboarding' => 'When someone joins', 'offboarding' => 'When someone leaves'] as $process => $heading)
        @php
            $list = collect($items[$process]);
            $theirs = $list->where('owner', 'employee')->where('kind', 'task')->pluck('title')->implode("\n");
            $acks = $list->where('kind', 'acknowledge')->map(fn ($i) => $i['title'].(filled($i['body'] ?? null) ? ' — '.$i['body'] : ''))->implode("\n");
            $hr = $list->where('owner', 'hr')->pluck('title')->implode("\n");
        @endphp
        <section class="rg-section">
            <h2>{{ $heading }}</h2>
            <form method="POST" action="{{ route('lifecycle.templates.update', $process) }}" class="dot-card dot-card--pad" style="display:grid;gap:12px;">
                @csrf
                @method('PUT')
                <div>
                    <label for="{{ $process }}-theirs">The person does</label>
                    <textarea id="{{ $process }}-theirs" name="employee_tasks" class="dot-input" rows="3">{{ $theirs }}</textarea>
                </div>
                <div>
                    <label for="{{ $process }}-acks">Policies they read and acknowledge <span class="dot-note">(Title — the text they read)</span></label>
                    <textarea id="{{ $process }}-acks" name="acknowledgements" class="dot-input" rows="3">{{ $acks }}</textarea>
                </div>
                <div>
                    <label for="{{ $process }}-hr">HR does</label>
                    <textarea id="{{ $process }}-hr" name="hr_tasks" class="dot-input" rows="4">{{ $hr }}</textarea>
                </div>
                <div style="display:flex;gap:8px;">
                    <button type="submit" class="dot-btn dot-btn-primary">Save</button>
                </div>
            </form>
            <form method="POST" action="{{ route('lifecycle.templates.reset', $process) }}" style="margin:6px 0 0;">
                @csrf
                @method('DELETE')
                <button type="submit" class="dot-btn dot-btn-ghost">Restore the Dot.HR defaults</button>
            </form>
        </section>
    @endforeach
</div>
</x-app-layout>
