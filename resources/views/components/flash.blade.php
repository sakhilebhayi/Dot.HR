@if (session('status'))
<div class="dot-flash dot-flash-ok" role="status">{{ session('status') }}</div>
@endif
@if (session('error'))
<div class="dot-flash dot-flash-err" role="alert">{{ session('error') }}</div>
@endif
