<x-app-layout>
<div class="page-pad" style="max-width:820px;">
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('my-work.index') }}#hr-matters">My work</a></p>
    <h1 class="rg-greet" style="margin-bottom:4px;">{{ $case->type === 'grievance' ? $case->title : $case->typeLabel() }}</h1>
    <p class="rg-sub" style="margin:0 0 14px;">
        {{ $case->reference() }}, {{ strtolower($case->statusLabel()) }}.
        @if ($case->type === 'grievance')
            {{ $case->restricted ? 'Only the business owner and whoever they ask to handle it can read this.' : 'Only HR can read this. Your manager and the person it is about cannot.' }}
        @else
            You see what HR has shared with you. Your manager and your colleagues cannot see this.
        @endif
    </p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    @if ($case->type === 'grievance')
        <div class="dot-card dot-card--pad" style="margin-bottom:14px;">
            <div class="dot-label">What you raised</div>
            <p style="margin:6px 0 0;white-space:pre-line;max-width:68ch;">{{ $case->summary }}</p>
        </div>
    @endif

    <div class="dot-card dot-card--pad">
        <div class="dot-label">What HR has shared, and your replies</div>
        @if ($entries->isEmpty())
            <p class="dot-note" style="margin:6px 0 0;">Nothing yet. You are told here, and by notification, as soon as HR writes to you.</p>
        @else
            <ol class="rg-timeline" style="margin-top:6px;">
                @foreach ($entries as $entry)
                    <li>
                        <time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->format('j M Y') }}<br>{{ $entry->created_at->format('H:i') }}</time>
                        <div class="{{ $entry->by_employee ? 'from-them' : '' }}">
                            <div class="head">
                                <strong>{{ $entry->by_employee ? 'You wrote' : $entry->kindLabel() }}</strong>
                                @if ($entry->kind === 'notice' && ! $entry->by_employee)
                                    @if ($entry->received_at)
                                        <span class="dot-badge dot-badge-ok">You confirmed receiving it {{ $entry->received_at->format('j M') }}</span>
                                    @endif
                                @endif
                            </div>
                            <p class="body">{{ $entry->body }}</p>
                            @if ($entry->kind === 'notice' && ! $entry->by_employee && ! $entry->received_at)
                                <form method="POST" action="{{ route('my-work.cases.received', $entry) }}" style="margin:8px 0 0;">
                                    @csrf
                                    <button type="submit" class="dot-btn">I received this notice</button>
                                    <span class="dot-note" style="margin-left:6px;">Confirms you got it, not that you agree.</span>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>

    @if ($case->isOpen())
        <div class="dot-card dot-card--pad" style="margin-top:14px;">
            @if ($case->status === 'decided' && $case->type !== 'incident' && ! $case->appealed_at)
                <div class="dot-label">Appeal the outcome</div>
                <form method="POST" action="{{ route('my-work.cases.appeal', $case) }}" style="display:grid;gap:8px;margin-top:6px;">
                    @csrf
                    <label for="ap-grounds" style="position:absolute;left:-9999px;">Why you are appealing</label>
                    <textarea id="ap-grounds" name="grounds" class="dot-input" rows="3" maxlength="5000" required placeholder="Why you think the outcome is wrong or unfair"></textarea>
                    <div><button type="submit" class="dot-btn">Lodge the appeal</button></div>
                </form>
                <div style="height:14px;"></div>
            @endif
            <div class="dot-label">Write to HR about this</div>
            <form method="POST" action="{{ route('my-work.cases.reply', $case) }}" style="display:grid;gap:8px;margin-top:6px;">
                @csrf
                <label for="rp-body" style="position:absolute;left:-9999px;">Your reply</label>
                <textarea id="rp-body" name="body" class="dot-input" rows="4" maxlength="10000" required placeholder="Your side, a question, or something HR should know"></textarea>
                <div><button type="submit" class="dot-btn dot-btn-primary">Send to HR</button></div>
            </form>
        </div>
    @endif
</div>
</x-app-layout>
