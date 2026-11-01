<x-app-layout>
<div class="page-pad" style="max-width:760px;">
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('employees.show', $employee) }}">{{ $employee->fullName() }}</a></p>
    <h1 style="margin:0;">Certificate of service</h1>
    <p class="rg-sub" style="margin:4px 0 16px;">Every employee is owed one when they leave (BCEA s42). Check each line: it is a formal document, and {{ $employee->first_name }} can open it from My work once issued.</p>

    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    <form method="POST" action="{{ route('leaving.certificate.store', $employee) }}" class="dot-card dot-card--pad" style="display:grid;gap:12px;">
        @csrf
        <div class="rg-form-row">
            <div><label for="cf-name">Employer</label><input id="cf-name" name="employer_name" class="dot-input" maxlength="160" required value="{{ old('employer_name', $defaults['employer_name']) }}"></div>
            <div><label for="cf-address">Employer’s address</label><input id="cf-address" name="employer_address" class="dot-input" maxlength="255" required value="{{ old('employer_address', $defaults['employer_address']) }}" placeholder="Street, town, postal code"></div>
        </div>
        <div><label for="cf-job">Job title, or a short description of the work</label><input id="cf-job" name="job_title" class="dot-input" maxlength="160" required value="{{ old('job_title', $defaults['job_title']) }}"></div>
        <div class="rg-form-row">
            <div><label for="cf-start">Started</label><input id="cf-start" name="start_date" type="date" class="dot-input" required value="{{ old('start_date', $defaults['start_date']) }}"></div>
            <div><label for="cf-end">Employment ended</label><input id="cf-end" name="end_date" type="date" class="dot-input" required value="{{ old('end_date', $defaults['end_date']) }}"></div>
        </div>
        <div>
            <label for="cf-pay">Remuneration at the date employment ended</label>
            <input id="cf-pay" name="remuneration" class="dot-input" maxlength="255" required value="{{ old('remuneration') }}" placeholder="R18 500 per month">
            <p class="dot-note" style="margin:4px 0 0;">Dot.HR does not hold pay, so type it from payroll. It is encrypted.</p>
        </div>
        <label class="dot-note" style="display:flex;gap:8px;align-items:flex-start;margin:0;">
            <input type="checkbox" name="reason_requested" value="1" @checked(old('reason_requested')) style="margin-top:3px;">
            <span>{{ $employee->first_name }} asked for the reason for leaving to be stated. Leave this unticked unless they asked: the law puts the reason on the certificate only on request.</span>
        </label>
        <div><label for="cf-reason">Reason, if they asked</label><input id="cf-reason" name="reason" class="dot-input" maxlength="255" value="{{ old('reason', $defaults['reason']) }}"></div>
        <div><button type="submit" class="dot-btn dot-btn-primary">Issue the certificate</button></div>
    </form>
</div>
</x-app-layout>
