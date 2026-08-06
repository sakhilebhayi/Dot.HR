@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-[var(--ink)]']) }}>
    {{ $value ?? $slot }}
</label>
