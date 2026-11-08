@csrf
<p class="text-sm text-slate-600 dark:text-slate-400">Fields marked * are required.</p>
@foreach (['name' => 'Product name', 'category' => 'Category', 'price' => 'Price', 'stock_quantity' => 'Stock quantity'] as $field => $label)
    <div>
        <label for="{{ $field }}" class="mb-2 block text-sm font-semibold">{{ $label }} *</label>
        <input id="{{ $field }}" name="{{ $field }}" type="{{ in_array($field, ['price', 'stock_quantity']) ? 'number' : 'text' }}" value="{{ old($field, $product->{$field}) }}" required
            @if ($field === 'name') maxlength="255" @endif
            @if ($field === 'category') maxlength="100" @endif
            @if ($field === 'price') min="0" max="9999999999.99" step="0.01" @endif
            @if ($field === 'stock_quantity') min="0" max="4294967295" step="1" @endif
            aria-invalid="{{ $errors->has($field) ? 'true' : 'false' }}"
            @if ($errors->has($field)) aria-describedby="{{ $field }}-error" @endif
            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 focus:border-teal-600 focus:outline-2 focus:outline-teal-600 dark:border-slate-700 dark:bg-slate-950">
        @error($field)<p id="{{ $field }}-error" class="mt-2 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>@enderror
    </div>
@endforeach
<div>
    <label for="supplier_id" class="mb-2 block text-sm font-semibold">Supplier</label>
    <select id="supplier_id" name="supplier_id" aria-invalid="{{ $errors->has('supplier_id') ? 'true' : 'false' }}" aria-describedby="supplier-help supplier-error" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 focus:border-teal-600 focus:outline-2 focus:outline-teal-600 dark:border-slate-700 dark:bg-slate-950">
        <option value="" @selected(old('supplier_id', $product->supplier_id) === null || old('supplier_id', $product->supplier_id) === '')>Unassigned</option>
        @foreach ($suppliers as $supplier)
            <option value="{{ $supplier->id }}" @selected(old('supplier_id', $product->supplier_id) == $supplier->id)>{{ $supplier->name }}{{ $supplier->trashed() ? ' (Deleted — current assignment)' : ($supplier->status === \App\SupplierStatus::Inactive ? ' (Inactive — current assignment)' : '') }}</option>
        @endforeach
    </select>
    <p id="supplier-help" class="mt-2 text-sm text-slate-500 dark:text-slate-400">Optional. New assignments require an Active supplier. An existing Inactive or Deleted supplier can be retained. Choose Unassigned to remove the assignment.</p>
    <p id="supplier-error" class="mt-2 text-sm text-red-700 dark:text-red-400">@error('supplier_id'){{ $message }}@enderror</p>
</div>
<div>
    <label for="description" class="mb-2 block text-sm font-semibold">Description</label>
    <textarea id="description" name="description" rows="5" aria-invalid="{{ $errors->has('description') ? 'true' : 'false' }}" aria-describedby="description-error" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 focus:border-teal-600 focus:outline-2 focus:outline-teal-600 dark:border-slate-700 dark:bg-slate-950">{{ old('description', $product->description) }}</textarea>
    <p id="description-error" class="mt-2 text-sm text-red-700 dark:text-red-400">@error('description'){{ $message }}@enderror</p>
</div>
<div>
    <label for="image" class="mb-2 block text-sm font-semibold">Product image</label>
    @if ($product->image_path)
        <img src="{{ Storage::disk('public')->url($product->image_path) }}" alt="Current image for {{ $product->name }}" class="mb-3 size-32 rounded-xl object-cover">
    @endif
    <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="image-help image-error" aria-invalid="{{ $errors->has('image') ? 'true' : 'false' }}" class="block w-full rounded-lg border border-slate-300 p-2 text-sm file:mr-4 file:rounded-md file:border-0 file:bg-teal-50 file:px-3 file:py-2 file:font-semibold file:text-teal-800 dark:border-slate-700 dark:file:bg-teal-950 dark:file:text-teal-200">
    <p id="image-help" class="mt-2 text-sm text-slate-500 dark:text-slate-400">JPEG, PNG, or WebP. Maximum 2 MB. Optional; leave blank to keep the current image. Select the file again if the form is returned with an error.</p>
    <p id="image-error" class="mt-2 text-sm text-red-700 dark:text-red-400">@error('image'){{ $message }}@enderror</p>
</div>
<div class="flex flex-wrap items-center gap-3 border-t border-slate-200 pt-5 dark:border-slate-800">
    <button type="submit" class="rounded-lg bg-teal-700 px-5 py-3 text-sm font-semibold text-white hover:bg-teal-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600">{{ $product->exists ? 'Save changes' : 'Create product' }}</button>
    <a href="{{ $product->exists ? route('products.show', $product) : route('products.index') }}" class="rounded-lg px-4 py-3 text-sm font-semibold hover:underline">Cancel</a>
</div>
