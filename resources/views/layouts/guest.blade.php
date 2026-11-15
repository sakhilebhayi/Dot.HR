<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        {{-- Roboto, from this site (R-52): no third-party font host. --}}
        <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-roboto.min.css') }}">
        <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-type.min.css') }}">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <!-- Styles -->
        @livewireStyles

        <style>
            :root {
                --paper: #faf7f0;
                --paper-deep: #f1ead9;
                --ink: #21255a;
                --ink-soft: #4c5085;
                --gold: #f0b91c;
                --gold-deep: #c98a09;
                --line: rgba(33, 37, 90, 0.14);
                --font-display: var(--dot-font-sans);
                --font-body: var(--dot-font-sans);
                --font-mono: var(--dot-font-sans);
                --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            }
            html { background: var(--paper); }
            body { font-family: var(--font-body); background: var(--paper); color: var(--ink); }
            .font-display { font-family: var(--font-display); font-optical-sizing: auto; }
            .font-mono { font-family: var(--font-mono); }
            /* R-52: up to 1024px every control on the sign-in family of pages is a 44px target and every field 16px (no iOS zoom). */
            @media (max-width: 1024px) {
                input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]), select, textarea { min-height: 44px; font-size: 16px; }
                form a, form button[type="submit"] { display: inline-flex; align-items: center; min-height: 44px; }
                form button[type="submit"] { justify-content: center; }
                label:has(> input[type="checkbox"]) { display: inline-flex; align-items: center; min-height: 44px; }
            }
        </style>
    </head>
    <body class="antialiased">
        <div class="font-sans text-[var(--ink)] antialiased">
            {{ $slot }}
        </div>

        @livewireScripts
    </body>
</html>
