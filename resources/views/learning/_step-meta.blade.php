{{-- One step described in sentences: what kind it is, who runs it, what it gives and grows. --}}
{{ \App\Models\LearningPathStep::KINDS[$step->kind] }}{{ $step->provider ? ', run by '.$step->provider : '' }}.
@if ($step->certification_name) Gives the {{ $step->certification_name }} certificate. @endif
@if ($step->competency) Grows {{ $step->competency->name }}. @endif
@if ($step->within_days && empty($dated)) Within {{ $step->within_days }} {{ Str::plural('day', $step->within_days) }} of starting. @endif
@if ($step->detail)<span style="display:block;margin-top:3px;color:var(--rg-ink);">{{ $step->detail }}</span>@endif
