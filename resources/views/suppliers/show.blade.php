<x-layouts.app :title="$supplier->name">
    <a href="{{ route('suppliers.index') }}" class="text-sm text-teal-700 hover:underline dark:text-teal-300">Back to suppliers</a>
    @if ($supplier->trashed())<p role="status" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-200">Deleted supplier · Read-only profile. Existing product assignments are retained.</p>@endif
    <article class="flex max-w-3xl flex-col gap-6 rounded-xl border border-slate-200 bg-white p-5 sm:p-8 dark:border-slate-800 dark:bg-slate-900">
        <div><p class="text-sm font-semibold text-teal-700 dark:text-teal-300">Supplier profile · {{ $supplier->status->name }}</p><h1 class="mt-2 break-words text-3xl font-bold">{{ $supplier->name }}</h1></div>
        <dl class="grid gap-6 sm:grid-cols-2">
            @foreach (['Contact person' => $supplier->contact_person, 'Email' => $supplier->email, 'Phone number' => $supplier->phone, 'Address' => $supplier->address ?: 'No address provided.', 'Created' => $supplier->created_at->format('d M Y, H:i'), 'Last updated' => $supplier->updated_at->format('d M Y, H:i')] as $label => $value)<div><dt class="text-sm text-slate-500 dark:text-slate-400">{{ $label }}</dt><dd class="mt-1 whitespace-pre-line break-words font-medium">{{ $value }}</dd></div>@endforeach
        </dl>
        @unless ($supplier->trashed())
            <div class="flex flex-wrap items-start gap-3 border-t border-slate-200 pt-5 dark:border-slate-800"><a href="{{ route('suppliers.edit', $supplier) }}" class="rounded-lg bg-teal-700 px-5 py-2 text-sm font-semibold text-white hover:bg-teal-800">Edit supplier</a><x-suppliers.delete-button :supplier="$supplier" /></div>
        @endunless
    </article>
    <h2 class="text-xl font-semibold">Associated products ({{ $supplier->products_count }})</h2>
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">Products currently assigned to this supplier</caption>
            <thead class="bg-slate-100 dark:bg-slate-800"><tr>@foreach (['Product', 'Category', 'Price', 'Available stock'] as $heading)<th scope="col" class="px-5 py-4 font-semibold">{{ $heading }}</th>@endforeach</tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($products as $product)<tr><td class="px-5 py-4"><a href="{{ route('products.show', $product) }}" class="font-semibold text-teal-800 hover:underline dark:text-teal-300">{{ $product->name }}</a></td><td class="px-5 py-4">{{ $product->category }}</td><td class="px-5 py-4 tabular-nums">{{ $product->price }}</td><td class="px-5 py-4 tabular-nums">{{ $product->stock_quantity }}</td></tr>@empty<tr><td colspan="4" class="px-5 py-10 text-center text-slate-500 dark:text-slate-400">No products are assigned to this supplier.</td></tr>@endforelse
            </tbody>
        </table>
    </div>
    {{ $products->links() }}
</x-layouts.app>
