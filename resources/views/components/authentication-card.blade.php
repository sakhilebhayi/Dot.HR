<div class="relative min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 px-4 overflow-hidden">
    {{-- Same hero photo as welcome.blade.php (colleagues collaborating around a laptop at a
    shared table, by Christina @ wocintechchat.com), with a light paper-toned scrim matching
    the welcome hero's own treatment. --}}
    <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1573164574572-cb89e39749b4?q=80&w=2400&auto=format&fit=crop');"></div>
    <div class="absolute inset-0" style="background: radial-gradient(ellipse 70% 65% at 50% 35%, var(--paper) 0%, rgba(250,247,240,0.94) 45%, rgba(250,247,240,0.7) 72%, rgba(250,247,240,0.35) 100%);"></div>

    <div class="relative z-10 mb-2">
        {{ $logo }}
    </div>

    <div class="relative z-10 w-full sm:max-w-md mt-6 px-6 py-6 bg-white border border-[var(--line)] shadow-sm overflow-hidden sm:rounded-xl">
        {{ $slot }}
    </div>
</div>
