<x-layouts.app title="Supplier profiles">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div><p class="text-sm font-semibold uppercase tracking-widest text-teal-700 dark:text-teal-400">Suppliers</p><h1 class="mt-1 text-3xl font-bold tracking-tight">Supplier profiles</h1><p class="mt-2 text-slate-600 dark:text-slate-400">Keep supplier contacts organized and up to date.</p></div>
        <a href="{{ route('suppliers.create') }}" class="rounded-lg bg-teal-700 px-5 py-3 text-sm font-semibold text-white hover:bg-teal-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600">Add supplier</a>
    </div>
    <form method="GET" action="{{ route('suppliers.index') }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <div class="min-w-48 flex-1"><label for="search" class="mb-2 block text-sm font-medium">Search suppliers</label><input id="search" name="search" type="search" value="{{ $search }}" maxlength="255" placeholder="Search by supplier, contact, email, or phone" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 focus:border-teal-600 focus:outline-2 focus:outline-teal-600 dark:border-slate-700 dark:bg-slate-950"></div>
        <button class="rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-300">Search</button>
        @if ($search !== '')<a href="{{ route('suppliers.index') }}" class="rounded-lg px-4 py-2 text-slate-600 underline dark:text-slate-300">Clear</a>@endif
    </form>
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">Supplier contact information, status, and management actions</caption>
            <thead class="bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300"><tr>@foreach (['Supplier', 'Contact person', 'Email', 'Phone', 'Status', 'Actions'] as $heading)<th scope="col" class="px-5 py-4 font-semibold">{{ $heading }}</th>@endforeach</tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($suppliers as $supplier)
                    <tr class="align-top">
                        <td class="min-w-40 px-5 py-4"><a href="{{ route('suppliers.show', $supplier) }}" class="font-semibold text-teal-800 hover:underline dark:text-teal-300">{{ $supplier->name }}</a></td>
                        <td class="px-5 py-4">{{ $supplier->contact_person }}</td><td class="px-5 py-4">{{ $supplier->email }}</td><td class="whitespace-nowrap px-5 py-4">{{ $supplier->phone }}</td>
                        <td class="px-5 py-4"><span @class(['inline-block rounded-full px-2.5 py-1 text-xs font-semibold', 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' => $supplier->status === \App\SupplierStatus::Active, 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' => $supplier->status === \App\SupplierStatus::Inactive])>{{ $supplier->status->name }}</span></td>
                        <td class="px-5 py-4"><div class="flex flex-wrap items-start gap-1"><a href="{{ route('suppliers.show', $supplier) }}" class="rounded-lg px-3 py-2 font-semibold text-teal-800 hover:underline dark:text-teal-300">View</a><a href="{{ route('suppliers.edit', $supplier) }}" class="rounded-lg px-3 py-2 font-semibold hover:underline">Edit</a><x-suppliers.delete-button :supplier="$supplier" /></div></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-5 py-16 text-center text-slate-500 dark:text-slate-400">{{ $search !== '' ? 'No suppliers match your search.' : 'No suppliers yet. Add your first supplier to get started.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $suppliers->links() }}
</x-layouts.app>
