<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Certificate of service, {{ $certificate->employee->fullName() }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600&family=Instrument+Serif&display=swap" rel="stylesheet">
    @vite(['resources/css/register.css', 'resources/js/app.js'])
    <style>
        /* A document, not a screen: one A4 sheet, ink on paper, The Register's type. */
        body { background: var(--rg-panel); color: var(--rg-ink); font: 400 15px/1.6 var(--rg-sans); margin: 0; }
        .bar { max-width: 210mm; margin: 0 auto; padding: 16px 16px 0; display: flex; gap: 10px; justify-content: space-between; align-items: center; }
        .sheet { background: var(--rg-ground); max-width: 210mm; min-height: 250mm; margin: 12px auto 40px; padding: 22mm 20mm; box-sizing: border-box; border-radius: 6px; }
        .sheet h1 { font: 400 34px/1.15 var(--rg-serif); margin: 0 0 4px; }
        .sheet .issuer { color: var(--rg-muted); margin: 0 0 28px; }
        .sheet dl { display: grid; grid-template-columns: 52mm minmax(0, 1fr); gap: 10px 18px; margin: 0; padding: 18px 0; border-top: 1px solid var(--rg-rule); border-bottom: 1px solid var(--rg-rule); }
        .sheet dt { color: var(--rg-muted); }
        .sheet dd { margin: 0; }
        .sheet .statement { margin: 24px 0 0; max-width: 62ch; }
        .sheet .sign { margin-top: 30mm; display: grid; grid-template-columns: 1fr 1fr; gap: 16mm; }
        .sheet .sign div { border-top: 1px solid var(--rg-ink); padding-top: 6px; font-size: 13px; color: var(--rg-muted); }
        .sheet .foot { margin-top: 18mm; font-size: 12px; color: var(--rg-muted); }
        @media (max-width: 600px) { .sheet { padding: 24px 18px; } .sheet dl { grid-template-columns: minmax(0, 1fr); gap: 2px; } .sheet dd { margin-bottom: 10px; } .sheet .sign { grid-template-columns: 1fr; } }
        @media print {
            @page { size: A4; margin: 0; }
            body { background: #fff; }
            .bar { display: none; }
            .sheet { margin: 0; border-radius: 0; max-width: none; min-height: auto; }
        }
    </style>
</head>
<body>
    <div class="bar">
        <a href="{{ $back }}" class="dot-btn dot-btn-ghost">Back</a>
        <button type="button" class="dot-btn dot-btn-primary" data-print>Print or save as PDF</button>
    </div>
    <main class="sheet">
        <h1>Certificate of service</h1>
        <p class="issuer">Issued by {{ $certificate->employer_name }} on {{ $certificate->issued_at->format('j F Y') }}</p>
        <dl>
            <dt>Employee</dt><dd>{{ $certificate->employee->fullName() }}</dd>
            <dt>Employer</dt><dd>{{ $certificate->employer_name }}</dd>
            <dt>Employer’s address</dt><dd>{{ $certificate->employer_address }}</dd>
            <dt>Employed from</dt><dd>{{ $certificate->start_date->format('j F Y') }}</dd>
            <dt>Employed until</dt><dd>{{ $certificate->end_date->format('j F Y') }}</dd>
            <dt>Job</dt><dd>{{ $certificate->job_title }}</dd>
            <dt>Remuneration at the end of employment</dt><dd>{{ $certificate->remuneration }}</dd>
            @if ($certificate->reason)<dt>Reason for leaving</dt><dd>{{ $certificate->reason }} <span style="color:var(--rg-muted);">(stated at the employee’s request)</span></dd>@endif
        </dl>
        <p class="statement">This certifies that the person named above was employed by {{ $certificate->employer_name }} for the period and in the work stated.</p>
        <div class="sign">
            <div>Signed for the employer</div>
            <div>Date</div>
        </div>
        <p class="foot">Certificate of service in terms of section 42 of the Basic Conditions of Employment Act, 1997.</p>
    </main>
</body>
</html>
