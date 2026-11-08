<x-layouts.app :title="$supplier->name">
    <a href="{{ route('suppliers.index') }}" class="text-sm text-teal-700 hover:underline dark:text-teal-300">Back to suppliers</a>
    <article class="flex max-w-3xl flex-col gap-6 rounded-xl border border-slate-200 bg-white p-5 sm:p-8 dark:border-slate-800 dark:bg-slate-900">
        <div><p class="text-sm font-semibold text-teal-700 dark:text-teal-300">Supplier profile · {{ $supplier->status->name }}</p><h1 class="mt-2 break-words text-3xl font-bold">{{ $supplier->name }}</h1></div>
        <dl class="grid gap-6 sm:grid-cols-2">
            @foreach (['Contact person' => $supplier->contact_person, 'Email' => $supplier->email, 'Phone number' => $supplier->phone, 'Address' => $supplier->address ?: 'No address provided.', 'Created' => $supplier->created_at->format('d M Y, H:i'), 'Last updated' => $supplier->updated_at->format('d M Y, H:i')] as $label => $value)<div><dt class="text-sm text-slate-500 dark:text-slate-400">{{ $label }}</dt><dd class="mt-1 whitespace-pre-line break-words font-medium">{{ $value }}</dd></div>@endforeach
        </dl>
        <div class="flex flex-wrap items-start gap-3 border-t border-slate-200 pt-5 dark:border-slate-800"><a href="{{ route('suppliers.edit', $supplier) }}" class="rounded-lg bg-teal-700 px-5 py-2 text-sm font-semibold text-white hover:bg-teal-800">Edit supplier</a><x-suppliers.delete-button :supplier="$supplier" /></div>
    </article>
</x-layouts.app>
