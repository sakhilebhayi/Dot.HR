<x-dot.search wire="query" name="q" label="Search Dot.HR" :placeholder="$personal ? 'Find a role or application' : 'Find a person, leave or position'" hint="Ctrl K"
    results-nav enter-first escape-clears autocomplete="off" x-on:focus.once="$wire.touch()"
    :form-attributes="['wire:submit.prevent' => 'submit']" :root-attributes="['wire:ignore.self' => '']">
    <x-slot:results>
        @if ($touched)
            <div class="dsr-quick">
                @if (! $typed)
                    <p class="dsr-foot">{{ $personal ? 'Type at least two letters to find your applications and open roles.' : 'Type at least two letters. Only what your access already lets you open is found.' }}</p>
                @else
                    @forelse ($groups as $group => $items)
                        <x-dot.search-head>{{ $group }}</x-dot.search-head>
                        @foreach ($items as $item)
                            <x-dot.search-row :href="$item['url']" :label="$item['label']" :tag="$item['sub']" wire:key="hs-{{ $group }}-{{ $loop->index }}-{{ md5($item['url']) }}" />
                        @endforeach
                    @empty
                        <p class="dsr-foot" role="status">Nothing you can open matches &ldquo;{{ \Illuminate\Support\Str::limit(trim($query), 60) }}&rdquo;.</p>
                    @endforelse
                @endif
            </div>
        @endif
    </x-slot:results>
</x-dot.search>
