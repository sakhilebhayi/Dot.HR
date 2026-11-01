<x-app-layout>
<div class="page-pad" style="max-width:900px;">
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('hiring.index') }}">Hiring</a></p>
    <h1 class="rg-greet">Your hiring steps.</h1>
    <p class="rg-sub">The stages stay the same for every business, because offers, interviews and what candidates see depend on them. Inside them, add the steps your business really takes, such as a phone screen or a reference check. Candidates never see steps.</p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    <div class="rg-grid">
        @foreach (\App\Models\HiringStep::STAGES as $stage)
            <div class="dot-card dot-card--pad s6">
                <div class="dot-label">{{ \App\Models\Application::STAGES[$stage] }}</div>
                @forelse ($steps->get($stage, collect()) as $step)
                    <div class="rg-entry rg-entry--tools" style="align-items:center;">
                        <div style="min-width:0;"><h4>{{ $step->name }}</h4><div class="m">{{ $inUse->get($step->id, 0) }} {{ Str::plural('application', $inUse->get($step->id, 0)) }} here now</div></div>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end;">
                            @unless ($loop->first)<form method="POST" action="{{ route('hiring.steps.move', [$step, 'up']) }}" style="margin:0;">@csrf<button type="submit" class="dot-btn dot-btn-ghost" style="min-height:28px;padding:2px 8px;" aria-label="Move {{ $step->name }} up">Up</button></form>@endunless
                            @unless ($loop->last)<form method="POST" action="{{ route('hiring.steps.move', [$step, 'down']) }}" style="margin:0;">@csrf<button type="submit" class="dot-btn dot-btn-ghost" style="min-height:28px;padding:2px 8px;" aria-label="Move {{ $step->name }} down">Down</button></form>@endunless
                            <form method="POST" action="{{ route('hiring.steps.retire', $step) }}" style="margin:0;" data-confirm="Retire {{ $step->name }}? Applications on it stay in {{ \App\Models\Application::STAGES[$stage] }}.">@csrf<button type="submit" class="dot-btn dot-btn-ghost" style="min-height:28px;padding:2px 8px;">Retire</button></form>
                        </div>
                    </div>
                @empty
                    <p class="dot-note" style="margin:6px 0 0;">No steps of your own here.</p>
                @endforelse
            </div>
        @endforeach
    </div>

    <form method="POST" action="{{ route('hiring.steps.store') }}" class="dot-card dot-card--pad" style="display:grid;gap:10px;margin-top:14px;max-width:560px;">
        @csrf
        <div class="dot-label" style="margin:0;">Add a step</div>
        <div class="rg-form-row">
            <div>
                <label for="st-stage">Inside</label>
                <select id="st-stage" name="stage" class="dot-input">
                    @foreach (\App\Models\HiringStep::STAGES as $stage)<option value="{{ $stage }}">{{ \App\Models\Application::STAGES[$stage] }}</option>@endforeach
                </select>
            </div>
            <div><label for="st-name">Name</label><input id="st-name" name="name" class="dot-input" maxlength="60" required placeholder="Phone screen"></div>
        </div>
        <div><button type="submit" class="dot-btn dot-btn-primary">Add it</button></div>
    </form>
</div>
</x-app-layout>
