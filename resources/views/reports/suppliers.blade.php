<x-reports.layout title="Supplier report">
    <div class="flex flex-wrap items-center justify-between gap-4"><p class="text-sm text-slate-600 dark:text-slate-400">Current supplier profiles only. Products are not linked to suppliers.</p><a href="{{ route('reports.suppliers.export') }}" class="rounded-lg border border-teal-700 px-4 py-2 font-semibold text-teal-800 dark:text-teal-300">Export CSV</a></div>
    <dl class="grid gap-4 sm:grid-cols-3"><x-reports.card label="Current suppliers · all pages" :value="$summary['total']" /><x-reports.card label="Active" :value="$summary['active']" /><x-reports.card label="Inactive" :value="$summary['inactive']" /></dl>
    <x-reports.table :headers="['Supplier', 'Contact person', 'Email', 'Phone', 'Address', 'Status']" caption="Current supplier contacts and active or inactive status">
        @forelse ($suppliers as $supplier)<tr class="align-top"><td class="px-5 py-4">{{ $supplier->name }}</td><td class="px-5 py-4">{{ $supplier->contact_person }}</td><td class="px-5 py-4">{{ $supplier->email }}</td><td class="whitespace-nowrap px-5 py-4">{{ $supplier->phone }}</td><td class="whitespace-pre-line px-5 py-4">{{ $supplier->address ?: 'No address provided.' }}</td><td class="px-5 py-4">{{ $supplier->status->name }}</td></tr>@empty<tr><td colspan="6" class="px-5 py-12 text-center text-slate-500 dark:text-slate-400">No suppliers available for reporting.</td></tr>@endforelse
    </x-reports.table>
    {{ $suppliers->links() }}
</x-reports.layout>
