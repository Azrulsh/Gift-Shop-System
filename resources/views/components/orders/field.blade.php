@props(['name', 'label'])
<div>
    <label for="{{ $name }}" class="mb-2 block text-sm font-semibold">{{ $label }}</label>
    {{ $slot }}
    @error($name)<p id="{{ $name }}-error" class="mt-2 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>@enderror
</div>
