<x-layouts.app title="Edit product">
    <div><a href="{{ route('products.show', $product) }}" class="text-sm text-teal-700 hover:underline dark:text-teal-300">Back to product</a><h1 class="mt-3 text-3xl font-bold">Edit product</h1></div>
    <form method="POST" action="{{ route('products.update', $product) }}" enctype="multipart/form-data" class="flex max-w-3xl flex-col gap-5 rounded-xl border border-slate-200 bg-white p-5 sm:p-8 dark:border-slate-800 dark:bg-slate-900">
        @method('PUT')
        @php($originalStock = old('original_stock_quantity', $product->stock_quantity))
        <input type="hidden" name="original_stock_quantity" value="{{ is_scalar($originalStock) ? $originalStock : $product->stock_quantity }}">
        @include('products._form', ['product' => $product])
    </form>
</x-layouts.app>
