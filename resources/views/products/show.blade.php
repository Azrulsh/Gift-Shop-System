<x-layouts.app :title="$product->name">
    <a href="{{ route('products.index') }}" class="text-sm text-teal-700 hover:underline dark:text-teal-300">Back to products</a>
    <article class="grid gap-8 rounded-xl border border-slate-200 bg-white p-5 sm:p-8 md:grid-cols-2 dark:border-slate-800 dark:bg-slate-900">
        <div>
            @if ($product->image_path)
                <img src="{{ Storage::disk('public')->url($product->image_path) }}" alt="{{ $product->name }}" class="aspect-square w-full rounded-xl bg-slate-100 object-contain dark:bg-slate-800">
            @else
                <div class="flex aspect-square items-center justify-center rounded-xl bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">No image available</div>
            @endif
        </div>
        <div class="flex flex-col gap-6">
            <div>
                <p class="text-sm font-semibold text-teal-700 dark:text-teal-300">{{ $product->category }}</p>
                <h1 class="mt-2 break-words text-3xl font-bold">{{ $product->name }}</h1>
            </div>
            <dl class="grid grid-cols-2 gap-4 rounded-xl bg-slate-50 p-5 dark:bg-slate-950">
                <div><dt class="text-sm text-slate-500 dark:text-slate-400">Price</dt><dd class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format((float) $product->price, 2) }}</dd></div>
                <div><dt class="text-sm text-slate-500 dark:text-slate-400">Stock quantity</dt><dd class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($product->stock_quantity) }}</dd></div>
            </dl>
            <div><h2 class="mb-2 font-semibold">Supplier</h2><x-products.supplier :supplier="$product->supplier" /></div>
            <div><h2 class="font-semibold">Description</h2><p class="mt-2 whitespace-pre-line break-words text-slate-600 dark:text-slate-300">{{ $product->description ?: 'No description provided.' }}</p></div>
            <div class="flex flex-wrap items-start gap-3">
                <a href="{{ route('products.edit', $product) }}" class="rounded-lg bg-teal-700 px-5 py-2 text-sm font-semibold text-white hover:bg-teal-800">Edit product</a>
                <x-products.delete-button :product="$product" />
            </div>
        </div>
    </article>
</x-layouts.app>
