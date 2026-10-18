<div>
    <x-label for="title" value="Title" />
    <input id="title" name="title" type="text" class="dot-input" value="{{ old('title', $position?->title) }}" required autofocus />
    <x-input-error for="title" class="mt-2" />
</div>
<div>
    <x-label for="department" value="Department" />
    <input id="department" name="department" type="text" class="dot-input" value="{{ old('department', $position?->department) }}" />
    <x-input-error for="department" class="mt-2" />
</div>
<div>
    <x-label for="description" value="Description" />
    <textarea id="description" name="description" class="dot-input" rows="3">{{ old('description', $position?->description) }}</textarea>
    <p class="dot-note" style="margin-top:0.35rem;">What the work is. Do not put anything about a particular person here.</p>
    <x-input-error for="description" class="mt-2" />
</div>
