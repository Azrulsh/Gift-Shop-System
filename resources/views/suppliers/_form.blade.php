@csrf
<p class="text-sm text-slate-600 dark:text-slate-400">Fields marked * are required.</p>
@foreach (['name' => ['Supplier name', 'text', 255, 'organization'], 'contact_person' => ['Contact person', 'text', 255, 'name'], 'email' => ['Email', 'email', 255, 'email'], 'phone' => ['Phone number', 'tel', 30, 'tel']] as $field => [$label, $type, $length, $autocomplete])
    <div>
        <label for="{{ $field }}" class="mb-2 block text-sm font-semibold">{{ $label }} *</label>
        <input id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" value="{{ old($field, $supplier->{$field}) }}" required maxlength="{{ $length }}" autocomplete="{{ $autocomplete }}" aria-invalid="{{ $errors->has($field) ? 'true' : 'false' }}"
            @if ($errors->has($field)) aria-describedby="{{ $field }}-error" @endif
            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 focus:border-teal-600 focus:outline-2 focus:outline-teal-600 dark:border-slate-700 dark:bg-slate-950">
        @error($field)<p id="{{ $field }}-error" class="mt-2 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>@enderror
    </div>
@endforeach
<div>
    <label for="address" class="mb-2 block text-sm font-semibold">Address</label>
    <textarea id="address" name="address" rows="4" maxlength="2000" autocomplete="street-address" aria-invalid="{{ $errors->has('address') ? 'true' : 'false' }}"
        @if ($errors->has('address')) aria-describedby="address-error" @endif
        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 focus:border-teal-600 focus:outline-2 focus:outline-teal-600 dark:border-slate-700 dark:bg-slate-950">{{ old('address', $supplier->address) }}</textarea>
    @error('address')<p id="address-error" class="mt-2 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>@enderror
</div>
<div>
    <label for="status" class="mb-2 block text-sm font-semibold">Status *</label>
    <select id="status" name="status" required aria-invalid="{{ $errors->has('status') ? 'true' : 'false' }}"
        @if ($errors->has('status')) aria-describedby="status-error" @endif
        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 focus:border-teal-600 focus:outline-2 focus:outline-teal-600 dark:border-slate-700 dark:bg-slate-950">
        @foreach (\App\SupplierStatus::cases() as $status)<option value="{{ $status->value }}" @selected(old('status', $supplier->status?->value ?? 'active') === $status->value)>{{ $status->name }}</option>@endforeach
    </select>
    @error('status')<p id="status-error" class="mt-2 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>@enderror
</div>
<div class="flex flex-wrap items-center gap-3 border-t border-slate-200 pt-5 dark:border-slate-800"><button type="submit" class="rounded-lg bg-teal-700 px-5 py-3 text-sm font-semibold text-white hover:bg-teal-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600">{{ $supplier->exists ? 'Save changes' : 'Create supplier' }}</button><a href="{{ $supplier->exists ? route('suppliers.show', $supplier) : route('suppliers.index') }}" class="rounded-lg px-4 py-3 text-sm font-semibold hover:underline">Cancel</a></div>
