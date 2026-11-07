<x-layouts.app title="Product information">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="text-sm font-semibold uppercase tracking-widest text-teal-700 dark:text-teal-400">Inventory</p>
            <h1 class="mt-1 text-3xl font-bold tracking-tight">Product information</h1>
            <p class="mt-2 text-slate-600 dark:text-slate-400">Manage your gifts, prices, and stock in one place.</p>
        </div>
        <a href="{{ route('products.create') }}" class="rounded-lg bg-teal-700 px-5 py-3 text-sm font-semibold text-white hover:bg-teal-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600">Add product</a>
    </div>
    <form method="GET" action="{{ route('products.index') }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <div class="min-w-48 flex-1">
            <label for="search" class="mb-2 block text-sm font-medium">Search products</label>
            <input id="search" name="search" type="search" value="{{ $search }}" maxlength="255" placeholder="Search by name or category" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 focus:border-teal-600 focus:outline-2 focus:outline-teal-600 dark:border-slate-700 dark:bg-slate-950">
        </div>
        <button class="rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-300">Search</button>
        @if ($search !== '')
            <a href="{{ route('products.index') }}" class="rounded-lg px-4 py-2 text-slate-600 underline dark:text-slate-300">Clear</a>
        @endif
    </form>
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Products with category, price, stock quantity, and management actions</caption>
                <thead class="bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    <tr>
                        @foreach (['Product', 'Category', 'Price', 'Stock', 'Actions'] as $heading)
                            <th scope="col" class="px-5 py-4 font-semibold">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($products as $product)
                        <tr class="align-top">
                            <td class="px-5 py-4">
                                <div class="flex min-w-48 items-center gap-3">
                                    @if ($product->image_path)
                                        <img src="{{ Storage::disk('public')->url($product->image_path) }}" alt="{{ $product->name }}" class="size-14 shrink-0 rounded-lg object-cover" loading="lazy">
                                    @else
                                        <span class="flex size-14 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-center text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">No image</span>
                                    @endif
                                    <a href="{{ route('products.show', $product) }}" class="font-semibold text-teal-800 hover:underline dark:text-teal-300">{{ $product->name }}</a>
                                </div>
                            </td>
                            <td class="px-5 py-4">{{ $product->category }}</td>
                            <td class="whitespace-nowrap px-5 py-4 tabular-nums">{{ number_format((float) $product->price, 2) }}</td>
                            <td class="px-5 py-4 tabular-nums">
                                <span @class(['inline-block rounded-full px-2.5 py-1 text-xs font-semibold', 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-200' => $product->stock_quantity === 0, 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' => $product->stock_quantity > 0])>{{ $product->stock_quantity === 0 ? 'Out of stock' : number_format($product->stock_quantity) }}</span>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex flex-wrap items-start gap-1">
                                    <a href="{{ route('products.show', $product) }}" class="rounded-lg px-3 py-2 font-semibold text-teal-800 hover:underline dark:text-teal-300">View</a>
                                    <a href="{{ route('products.edit', $product) }}" class="rounded-lg px-3 py-2 font-semibold hover:underline">Edit</a>
                                    <x-products.delete-button :product="$product" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-16 text-center text-slate-500 dark:text-slate-400">{{ $search !== '' ? 'No products match your search.' : 'No products yet. Add your first product to get started.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    {{ $products->links() }}
</x-layouts.app>
