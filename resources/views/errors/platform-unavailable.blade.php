{{--
    Shown with a 503 when InfoDot has switched Dot.HR off (maintenance or unavailable). It says so plainly and reads nothing of the
    company's records: everything already here is kept exactly as it is. It is not a purchase or permission problem.
--}}
@extends('errors._ecosystem-layout', ['code' => 503, 'plain' => true])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#c98a09" stroke-width="1.75"><path d="M14.7 6.3a4 4 0 00-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 005.4-5.4l-2.65 2.65a1.5 1.5 0 01-2.1-2.1L14.7 6.3z" stroke-linecap="round" stroke-linejoin="round"/></svg>
@endsection

@section('title', 'Dot.HR is not available right now')
@section('message', ($message ?? 'Dot.HR is temporarily unavailable.').' Everything recorded here is kept as it is.')

@section('header-action')
    @if ($signedIn ?? false)
        <form method="POST" action="{{ route('logout') }}" style="margin: 0;">
            @csrf
            <button type="submit" style="background-color: transparent; border: 0; padding: 12px 0; color: rgba(255,255,255,0.85); font: inherit; font-size: 14px; cursor: pointer;">Sign out</button>
        </form>
    @endif
@endsection
