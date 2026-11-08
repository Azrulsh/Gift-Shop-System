<div data-order-row class="grid gap-3 rounded-lg border border-slate-200 p-4 sm:grid-cols-12 dark:border-slate-700">
    <div class="sm:col-span-6">
        <label data-product-label for="product-{{ $index }}" class="mb-2 block text-sm font-semibold">Product</label>
        <select data-product id="product-{{ $index }}" name="items[{{ $index }}][product_id]" required aria-invalid="{{ $errors->has('items.'.$index.'.product_id') ? 'true' : 'false' }}" aria-describedby="product-{{ $index }}-error" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 dark:border-slate-700 dark:bg-slate-950">
            <option value="">Select a product</option>
            @foreach ($products as $product)
                <option value="{{ $product->id }}" data-price="{{ $product->price }}" @selected(($row['product_id'] ?? '') == $product->id)>{{ $product->name }} — {{ $product->price }} · {{ $product->stock_quantity }} available</option>
            @endforeach
        </select>
        <p id="product-{{ $index }}-error" class="mt-2 text-sm text-red-700 dark:text-red-400">@error('items.'.$index.'.product_id'){{ $message }}@enderror</p>
    </div>
    <div class="sm:col-span-2"><label data-quantity-label for="quantity-{{ $index }}" class="mb-2 block text-sm font-semibold">Quantity</label><input data-quantity id="quantity-{{ $index }}" name="items[{{ $index }}][quantity]" type="number" min="1" max="1000000" step="1" value="{{ is_scalar($row['quantity'] ?? 1) ? ($row['quantity'] ?? 1) : 1 }}" required aria-invalid="{{ $errors->has('items.'.$index.'.quantity') ? 'true' : 'false' }}" aria-describedby="quantity-{{ $index }}-error" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 dark:border-slate-700 dark:bg-slate-950"><p id="quantity-{{ $index }}-error" class="mt-2 text-sm text-red-700 dark:text-red-400">@error('items.'.$index.'.quantity'){{ $message }}@enderror</p></div>
    <div class="sm:col-span-3"><p class="mb-2 text-sm font-semibold">Subtotal</p><output data-subtotal class="block py-2.5 tabular-nums">Calculated on save</output></div>
    <div class="flex items-end sm:col-span-1"><button data-remove-item type="button" class="rounded-lg px-2 py-2.5 text-sm font-semibold text-red-700 dark:text-red-400">Remove</button></div>
</div>
